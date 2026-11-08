<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Imap;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The known folders of the old server compared with the folders of the prepared generation once more,
 * in the hand over, after the final synchronization of the old server: a folder the user created there
 * after the snapshot was accepted has no pair yet.
 *
 * The comparison runs in the database, by the rules of a pair {@see FolderPairRules}, and only a folder
 * left without a pair costs a conversation with the target server: it is brought there
 * {@see TargetFolderCreator} and added to the folders of the prepared generation. A folder of the inbox
 * or of a system role without a pair is never created and stops the hand over with its role.
 *
 * Nothing is written before the network part is through: a pass broken off by the target server
 * leaves the stored state as it was, and its repeat finds the folders it did create by the addressed
 * lookup. The network part runs under the lease of the mailbox, so it keeps to the time of its creator
 * {@see TargetFolderCreator::hasTimeLeft()}: the folders it did not get to are the next pass's, and the
 * pass that leaves them does not count against the limit of returns.
 */
class LateFolderReconciler
{
	/**
	 * How many times one operation may go back to the tail for the folders a hand over brought into the
	 * target server. Past it the late folders are not created, and one more return walks their letters
	 * into the inbox.
	 */
	public const MAX_FOLDER_RETURNS = 3;
	public const MAX_CREATE_FAILURES = 3;

	public const REASON_RETURN_LIMIT = 'return_limit';

	/**
	 * @param array<int, array<string, mixed>> $knownDirectories The known folders of the old server,
	 *        in the shape of {@see MigrationService::knownActiveDirectories()}.
	 * @param string[] $notCreatedSources Paths of the folders the operation already failed to create.
	 * @param array<int, array{source: string, attempts: int, reason: string}> $createFailures Temporary
	 *        CREATE failures recorded by earlier passes.
	 * @param callable(): Imap $connectTarget Opens the IMAP connection of the target server.
	 * @param TargetFolderCreator|null $creator The creator with the deadline of this pass.
	 * @param (callable(array<int, array{generation: int, hash: string}>): bool)|null $hasNewLetters Whether
	 *        these folders the operation failed to create got letters the tail has not walked yet
	 *        {@see TailAppendService::hasLettersBeyondItsWalks()}.
	 * @return Result Data: pairs [{source, target, created}], notCreated [{source, reason, directoryId}],
	 *         returned - whether the operation has to go back to the tail, reopen [{generation, hash}] -
	 *         the folders of the old server the tail has to walk again, partial - whether the time
	 *         ran out before every folder was taken up. A failure carries "missingRole" in the custom
	 *         data when a folder with a role has no pair.
	 */
	public function reconcile(
		int $mailboxId,
		int $generationId,
		array $knownDirectories,
		array $notCreatedSources,
		array $createFailures,
		int $returns,
		callable $connectTarget,
		?TargetFolderCreator $creator = null,
		?callable $hasNewLetters = null,
	): Result
	{
		$result = new Result();
		$registry = $this->readRegistry($mailboxId, $generationId);
		$notCreatedSources = array_fill_keys(array_map('strval', $notCreatedSources), true);
		$createFailures = self::createFailuresBySource($createFailures);

		usort($knownDirectories, static fn (array $left, array $right): int => [
			(int)($left['LEVEL'] ?? 0),
			(int)($left['ID'] ?? 0),
		] <=> [
			(int)($right['LEVEL'] ?? 0),
			(int)($right['ID'] ?? 0),
		]);

		$client = null;
		$target = static function () use (&$client, $connectTarget): Imap {
			return $client ??= $connectTarget();
		};

		/** @var array<int, array{name: string, delim: string, segments: string[]}> $resolved */
		$resolved = [];
		$taken = [];
		$unpaired = [];
		$nameCollisions = [];
		$notCreatedIds = [];
		$roleDirectories = [];
		foreach ($knownDirectories as $known)
		{
			$id = (int)($known['ID'] ?? 0);
			$role = FolderPairRules::knownFolderRole($known, FolderPairRules::systemRole((array)($known['FLAGS'] ?? [])));
			$candidates = $this->candidatesOf($known, $role, $registry, $resolved, $taken);

			if ($candidates === [] && $role !== null && $role !== FolderPairRules::ROLE_INBOX)
			{
				/*
					The acceptance brought in only the roles the old server had then, drafts among them only
					when it had them: a role the old server got since is looked up on the target server
					before it stops anything.
				*/
				$found = $this->findRoleOnTheTarget($target(), $role);
				if ($found === false)
				{
					return $result->addError(new Error(
						'The target IMAP server did not answer while its folders were being compared',
						MigrationService::ERROR_CONNECTION_UNAVAILABLE,
					));
				}
				if ($found !== null && !isset($taken[(string)$found['name']]))
				{
					$roleDirectories[] = self::roleDirectoryOf($found, $known);
					$candidates = [self::pairOf($found)];
				}
			}

			if ($candidates === [] && $role !== null)
			{
				return $result->addError(new Error(
					sprintf('The known IMAP folder %s has no pair on the target server (role %s)', (string)($known['PATH'] ?? ''), $role),
					$role === FolderPairRules::ROLE_INBOX
						? MigrationService::ERROR_INCOME_FOLDER_MISSING
						: MigrationService::ERROR_DIRECTORY_ROLES_AMBIGUOUS,
					['missingRole' => $role],
				));
			}

			if ($candidates === [])
			{
				if (isset($notCreatedSources[(string)($known['PATH'] ?? '')]))
				{
					$notCreatedIds[] = $id;
				}
				else
				{
					$unpaired[] = $known;
				}

				continue;
			}

			if ($role === null && count($candidates) > 1)
			{
				$unpaired[] = $known;
				$nameCollisions[$id] = true;

				continue;
			}

			$pair = reset($candidates);
			$taken[$pair['name']] = true;
			$resolved[$id] = $pair;
		}

		if ($roleDirectories !== [])
		{
			(new MailboxDirectoryHelper(
				$mailboxId,
				null,
				GenerationScope::exact($generationId),
			))->appendDbDirsSnapshot($roleDirectories);
		}

		/*
			A folder the operation failed to create has no pair to receive letters on the new server, so
			what the old one delivered into it after the tail walked it reaches the inbox only by another
			walk. Past the last return nothing walks it any more.
		*/
		$stale = [];
		if ($notCreatedIds !== [] && $hasNewLetters !== null && $returns <= self::MAX_FOLDER_RETURNS)
		{
			$stale = $this->folderKeysOf($mailboxId, $notCreatedIds);
			if ($stale !== [] && !$hasNewLetters($stale))
			{
				$stale = [];
			}
		}

		if ($unpaired === [])
		{
			return $result->setData([
				'pairs' => [],
				'notCreated' => [],
				'returned' => $stale !== [],
				'reopen' => $stale,
				'partial' => false,
			]);
		}

		if ($returns >= self::MAX_FOLDER_RETURNS)
		{
			// The letters of these folders still reach the inbox, by one last return that creates nothing
			$lastWalk = $returns === self::MAX_FOLDER_RETURNS;

			return $result->setData([
				'pairs' => [],
				'notCreated' => array_map(
					static fn (array $known): array => self::notCreatedFolder($known, self::REASON_RETURN_LIMIT),
					$unpaired,
				),
				'returned' => $lastWalk,
				'reopen' => $lastWalk ? self::mergeKeys($this->folderKeysOf($mailboxId, array_column($unpaired, 'ID')), $stale) : [],
				'partial' => false,
			]);
		}

		$provisioned = $this->provision(
			$unpaired,
			$registry,
			$resolved,
			$taken,
			$target,
			$creator ?? new TargetFolderCreator(),
			$createFailures,
			$nameCollisions,
		);
		if (!$provisioned->isSuccess())
		{
			return $provisioned;
		}

		$data = $provisioned->getData();
		if ($data['directories'] !== [])
		{
			(new MailboxDirectoryHelper(
				$mailboxId,
				null,
				GenerationScope::exact($generationId),
			))->appendDbDirsSnapshot($data['directories']);
		}

		/*
			A pair found rather than created is walked again as well: the target server got the folder
			after the first tail - from a pass broken off after its CREATE, or from somebody else - and
			the letters the old one received since are not in it.
		*/
		$reopenIds = array_merge($data['pairedIds'], array_column($data['notCreated'], 'directoryId'));

		return $result->setData([
			'pairs' => $data['pairs'],
			'notCreated' => $data['notCreated'],
			'returned' => true,
			'reopen' => self::mergeKeys($this->folderKeysOf($mailboxId, $reopenIds), $stale),
			'partial' => $data['partial'],
		]);
	}

	/**
	 * @param array<int, array{name: string, delim: string, segments: string[]}> $resolved
	 * @param array<string, true> $taken
	 * @return array<int, array{name: string, delim: string, segments: string[]}>
	 */
	private function candidatesOf(array $known, ?string $role, array $registry, array $resolved, array $taken): array
	{
		$parentId = (int)($known['PARENT_ID'] ?? 0);
		$name = (string)($known['NAME'] ?? '');

		if ($role !== null)
		{
			$matches = array_filter(
				$registry,
				static fn (array $row): bool => $role === FolderPairRules::ROLE_INBOX
					? $row['inbox']
					: FolderPairRules::systemRole($row['flags']) === $role,
			);
		}
		elseif ($parentId > 0)
		{
			$parent = $resolved[$parentId] ?? null;
			if ($parent === null)
			{
				return [];
			}

			$expected = $parent['name'] . $parent['delim'] . $name;
			$matches = FolderPairRules::matchingDirectories($expected, array_values(array_filter(
				$registry,
				static fn (array $row): bool => !isset($taken[$row['name']]),
			)), false);
		}
		else
		{
			$roots = array_values(array_filter(
				$registry,
				static fn (array $row): bool => $row['level'] === 1
					&& !isset($taken[$row['name']]),
			));
			$matches = FolderPairRules::matchingDirectories($name, $roots, true);
		}

		return array_values($matches);
	}

	/**
	 * @param array<int, array<string, mixed>> $unpaired
	 * @param array<int, array{name: string, delim: string, segments: string[]}> $resolved
	 * @param array<string, true> $taken
	 * @param callable(): Imap $target Opens the IMAP connection only when a folder needs the server.
	 * @param array<string, array{attempts: int, reason: string}> $createFailures
	 * @param array<int, true> $nameCollisions
	 */
	private function provision(
		array $unpaired,
		array $registry,
		array $resolved,
		array $taken,
		callable $target,
		TargetFolderCreator $creator,
		array $createFailures,
		array $nameCollisions,
	): Result
	{
		$rootDelimiter = null;
		foreach ($registry as $row)
		{
			if ($row['delim'] !== '' && mb_strtoupper($row['delim']) !== 'NIL')
			{
				$rootDelimiter = $row['delim'];

				break;
			}
		}

		$pairs = [];
		$notCreated = [];
		$directories = [];
		$pairedIds = [];
		$partial = false;
		foreach ($unpaired as $known)
		{
			// At least one folder a pass, so that a pass cut short by the time still moves on
			if (($pairs !== [] || $notCreated !== []) && !$creator->hasTimeLeft())
			{
				$partial = true;

				break;
			}

			$id = (int)$known['ID'];
			if (isset($nameCollisions[$id]))
			{
				$notCreated[] = self::notCreatedFolder($known, TargetFolderCreator::REASON_NAME_COLLISION);

				continue;
			}

			$source = (string)($known['PATH'] ?? '');
			$failure = $createFailures[$source] ?? null;
			$createMissing = $failure === null || $failure['attempts'] < self::MAX_CREATE_FAILURES;

			$parentId = (int)($known['PARENT_ID'] ?? 0);
			$name = (string)($known['NAME'] ?? '');
			$parent = $parentId > 0 ? ($resolved[$parentId] ?? null) : null;

			if ($parentId > 0 && $parent === null)
			{
				$notCreated[] = self::notCreatedFolder($known, TargetFolderCreator::REASON_PARENT_NOT_CREATED);

				continue;
			}
			if ($parent === null && self::isNameTaken($name, $taken))
			{
				$notCreated[] = self::notCreatedFolder($known, TargetFolderCreator::REASON_NAME_COLLISION);

				continue;
			}

			$outcome = $creator->ensure(
				$target(),
				$parent === null ? null : ['name' => $parent['name'], 'delim' => $parent['delim']],
				$name,
				$parent === null ? $rootDelimiter : null,
				false,
				null,
				$createMissing,
			);
			if ($outcome['outcome'] === TargetFolderCreator::OUTCOME_RETRY)
			{
				return (new Result())->addError(new Error(
					'The target IMAP server did not answer while its folders were being created',
					MigrationService::ERROR_CONNECTION_UNAVAILABLE,
				));
			}
			if ($outcome['outcome'] === TargetFolderCreator::OUTCOME_CREATE_FAILED)
			{
				return (new Result())->addError(new Error(
					'The target IMAP server did not answer while its folders were being created',
					MigrationService::ERROR_CONNECTION_UNAVAILABLE,
					[
						'source' => $source,
						'reason' => (string)$outcome['reason'],
					],
				));
			}

			$found = $outcome['directory'];
			if ($found === null || self::isNameTaken((string)($found['name'] ?? ''), $taken))
			{
				$notCreated[] = self::notCreatedFolder(
					$known,
					$found === null
						? ($createMissing ? (string)$outcome['reason'] : (string)$failure['reason'])
						: TargetFolderCreator::REASON_NAME_COLLISION,
				);

				continue;
			}

			$targetName = (string)$found['name'];
			$delimiter = (string)($found['delim'] ?? '');
			$segments = [...($parent['segments'] ?? []), FolderPairRules::lastPathPart($targetName, $delimiter)];
			$directories[] = [
				'name' => $targetName,
				'delim' => $delimiter,
				'flags' => array_values((array)($known['FLAGS'] ?? [])),
				'path' => $segments,
				'is_sync' => (int)($known['IS_SYNC'] ?? 1),
			];
			$taken[$targetName] = true;
			$resolved[$id] = ['name' => $targetName, 'delim' => $delimiter, 'segments' => $segments];
			$pairs[] = [
				'source' => (string)($known['PATH'] ?? $name),
				'target' => $targetName,
				'created' => (bool)$outcome['created'],
			];
			$pairedIds[] = $id;
		}

		return (new Result())->setData([
			'pairs' => $pairs,
			'notCreated' => $notCreated,
			'directories' => $directories,
			'pairedIds' => $pairedIds,
			'partial' => $partial,
		]);
	}

	/**
	 * @param array<int, array{source: string, attempts: int, reason: string}> $failures
	 * @return array<string, array{attempts: int, reason: string}>
	 */
	private static function createFailuresBySource(array $failures): array
	{
		$bySource = [];
		foreach ($failures as $failure)
		{
			$source = (string)($failure['source'] ?? '');
			if ($source === '')
			{
				continue;
			}

			$bySource[$source] = [
				'attempts' => max(0, (int)($failure['attempts'] ?? 0)),
				'reason' => (string)($failure['reason'] ?? ''),
			];
		}

		return $bySource;
	}

	/**
	 * @return array|false|null The one root folder of the target server holding that role, null when
	 *         there is none or more than one, false when the listing failed.
	 */
	private function findRoleOnTheTarget(Imap $client, string $role): array|false|null
	{
		$roots = $client->listex('', '%', $error);
		if ($roots === false)
		{
			return false;
		}

		$matches = array_values(array_filter(
			(array)$roots,
			static fn (array $item): bool => FolderPairRules::systemRole((array)($item['flags'] ?? [])) === $role,
		));

		return count($matches) === 1 ? $matches[0] : null;
	}

	private static function roleDirectoryOf(array $found, array $known): array
	{
		$name = (string)$found['name'];
		$delimiter = (string)($found['delim'] ?? '');

		return [
			'name' => $name,
			'delim' => $delimiter,
			'flags' => array_values((array)($found['flags'] ?? [])),
			'path' => [FolderPairRules::lastPathPart($name, $delimiter)],
			'is_sync' => (int)($known['IS_SYNC'] ?? 0),
		];
	}

	/**
	 * @return array{name: string, delim: string, segments: string[]}
	 */
	private static function pairOf(array $found): array
	{
		$name = (string)$found['name'];

		return ['name' => $name, 'delim' => (string)($found['delim'] ?? ''), 'segments' => [$name]];
	}

	/**
	 * The folders of the prepared generation as candidates of a pair.
	 *
	 * @return array<int, array{name: string, delim: string, flags: string[], level: int, inbox: bool, segments: string[]}>
	 */
	private function readRegistry(int $mailboxId, int $generationId): array
	{
		$rows = MailboxDirectoryTable::getList([
			'select' => ['PATH', 'DELIMITER', 'LEVEL', 'IS_INCOME', 'IS_OUTCOME', 'IS_DRAFT', 'IS_TRASH', 'IS_SPAM'],
			'filter' => GenerationScope::exact($generationId)->apply(['=MAILBOX_ID' => $mailboxId]),
			'order' => ['LEVEL' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		$registry = [];
		foreach ($rows as $row)
		{
			$flags = [];
			foreach (['IS_OUTCOME' => '\\Sent', 'IS_DRAFT' => '\\Drafts', 'IS_TRASH' => '\\Trash', 'IS_SPAM' => '\\Junk'] as $field => $flag)
			{
				if ((int)$row[$field] === MailboxDirectoryTable::ACTIVE)
				{
					$flags[] = $flag;
				}
			}

			$path = (string)$row['PATH'];
			$delimiter = (string)$row['DELIMITER'];
			$level = (int)$row['LEVEL'];
			$hasDelimiter = $delimiter !== '' && mb_strtoupper($delimiter) !== 'NIL';
			$registry[] = [
				'name' => $path,
				'delim' => $delimiter,
				'flags' => $flags,
				'level' => $level,
				'inbox' => (int)$row['IS_INCOME'] === MailboxDirectoryTable::ACTIVE
					|| ($level === 1 && mb_strtoupper($path) === 'INBOX'),
				'segments' => $hasDelimiter ? explode($delimiter, $path) : [$path],
			];
		}

		return $registry;
	}

	/**
	 * @param int[] $directoryIds
	 * @return array<int, array{generation: int, hash: string}>
	 */
	private function folderKeysOf(int $mailboxId, array $directoryIds): array
	{
		if ($directoryIds === [])
		{
			return [];
		}

		$rows = MailboxDirectoryTable::getList([
			'select' => ['GENERATION_ID', 'DIR_MD5'],
			'filter' => GenerationScope::servingMailbox($mailboxId)->apply([
				'=MAILBOX_ID' => $mailboxId,
				'@ID' => array_values(array_unique(array_map('intval', $directoryIds))),
			]),
			'order' => ['ID' => 'ASC'],
		])->fetchAll();

		return array_map(
			static fn (array $row): array => ['generation' => (int)$row['GENERATION_ID'], 'hash' => (string)$row['DIR_MD5']],
			$rows,
		);
	}

	/**
	 * @param array<int, array{generation: int, hash: string}> $keys
	 * @param array<int, array{generation: int, hash: string}> $more
	 * @return array<int, array{generation: int, hash: string}>
	 */
	private static function mergeKeys(array $keys, array $more): array
	{
		$merged = [];
		foreach ([...$keys, ...$more] as $key)
		{
			$merged[$key['generation'] . ':' . $key['hash']] = $key;
		}

		return array_values($merged);
	}

	private static function notCreatedFolder(array $known, string $reason): array
	{
		return [
			'source' => (string)($known['PATH'] ?? $known['NAME'] ?? ''),
			'reason' => $reason,
			'directoryId' => (int)($known['ID'] ?? 0),
		];
	}

	/**
	 * @param array<string, true> $taken
	 */
	private static function isNameTaken(string $name, array $taken): bool
	{
		foreach (array_keys($taken) as $takenName)
		{
			if (
				mb_strtoupper(FolderPairRules::canonicalSegment((string)$takenName))
				=== mb_strtoupper(FolderPairRules::canonicalSegment($name))
			)
			{
				return true;
			}
		}

		return false;
	}
}
