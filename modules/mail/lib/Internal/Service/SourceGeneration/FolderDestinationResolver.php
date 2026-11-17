<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxDirectoryTable;

/**
 * The folder of the prepared generation a letter of a folder of the old server belongs in.
 *
 * One answer for every pass that puts a letter of the old server into the new one, asked in a
 * fixed order: the folder of the same system role, the pair the transfer recorded for the folder,
 * the one folder of the same path. A folder of the new server is an answer only while the import
 * of the prepared generation reads it: a letter put into any other would never be read back.
 *
 * No answer is an ordinary outcome and not a failure: what becomes of such a letter is for the
 * caller to decide.
 */
final class FolderDestinationResolver
{
	/** The folder of the same system role */
	public const ORIGIN_ROLE = 'role';
	/** The pair of a folder the transfer created on the new server */
	public const ORIGIN_PAIR_CREATED = 'pair_created';
	/** The pair of a folder the transfer found on the new server already */
	public const ORIGIN_PAIR_FOUND = 'pair_found';
	/** The one folder of the new server whose path is the path of the folder */
	public const ORIGIN_PATH_MATCH = 'path_match';

	private const DIRECTORY_FIELDS = [
		'GENERATION_ID',
		'DIR_MD5',
		'PATH',
		'DELIMITER',
		'LEVEL',
		'IS_SYNC',
		MailboxDirectoryTable::TYPE_INCOME,
		MailboxDirectoryTable::TYPE_OUTCOME,
		MailboxDirectoryTable::TYPE_DRAFT,
		MailboxDirectoryTable::TYPE_TRASH,
		MailboxDirectoryTable::TYPE_SPAM,
	];

	private readonly SystemRoleMap $roles;

	/** @var array<int, array<string, array<string, mixed>>> Generation => folder hash => its row */
	private array $folders = [];

	/** @var array<string, array<string, mixed>> Folder hash => row, synchronized folders of the prepared one */
	private array $syncedTargets = [];

	private function __construct(
		private readonly int $preparedGenerationId,
		array $directoryRows,
		private readonly FolderPairMap $pairs,
	)
	{
		$this->roles = SystemRoleMap::fromRows($directoryRows);

		foreach ($directoryRows as $row)
		{
			$generationId = (int)$row['GENERATION_ID'];
			$hash = (string)$row['DIR_MD5'];
			if ($hash === '')
			{
				continue;
			}

			$this->folders[$generationId][$hash] ??= $row;

			if (
				$generationId === $preparedGenerationId
				&& (int)($row['IS_SYNC'] ?? 0) === MailboxDirectoryTable::ACTIVE
			)
			{
				$this->syncedTargets[$hash] ??= $row;
			}
		}
	}

	/**
	 * The folders of every generation of the mailbox, read by one query, and the pairs the
	 * transfer recorded for the prepared generation.
	 */
	public static function ofOperation(int $mailboxId, int $preparedGenerationId): self
	{
		if ($mailboxId <= 0 || $preparedGenerationId <= 0)
		{
			return self::fromRows($preparedGenerationId, [], FolderPairMap::fromTargets($preparedGenerationId, []));
		}

		return self::fromRows(
			$preparedGenerationId,
			MailboxDirectoryTable::getList([
				'select' => self::DIRECTORY_FIELDS,
				'filter' => ['=MAILBOX_ID' => $mailboxId],
				// The order a role is resolved by, the same as the one of the folder helper
				'order' => ['LEVEL' => 'ASC', 'ID' => 'ASC'],
			])->fetchAll(),
			FolderPairMap::ofGeneration($mailboxId, $preparedGenerationId),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $directoryRows Rows of b_mail_mailbox_dir of every
	 *        generation, ordered by LEVEL and ID.
	 */
	public static function fromRows(int $preparedGenerationId, array $directoryRows, FolderPairMap $pairs): self
	{
		return new self($preparedGenerationId, $directoryRows, $pairs);
	}

	/**
	 * @return array{path: string, hash: string, origin: string, role: ?string}|null The folder of
	 *         the prepared generation, how it was found and, when found by its role, that role -
	 *         one of {@see SystemRoleMap::ROLES} or drafts. Null when no rule gives an answer.
	 */
	public function destinationOf(int $sourceGenerationId, string $sourceDirHash): ?array
	{
		if ($sourceGenerationId === $this->preparedGenerationId || $sourceDirHash === '')
		{
			return null;
		}

		return $this->byRole($sourceGenerationId, $sourceDirHash)
			?? $this->byPair($sourceGenerationId, $sourceDirHash)
			?? $this->byPath($sourceGenerationId, $sourceDirHash)
		;
	}

	/**
	 * A role the new server does not synchronize is no answer: the folder goes on to the other
	 * rules as a folder of the user would.
	 */
	private function byRole(int $sourceGenerationId, string $sourceDirHash): ?array
	{
		$roles = array_filter(
			SystemRoleMap::ROLES,
			fn (string $role): bool => $this->roles->holds($sourceGenerationId, $sourceDirHash, $role),
		);
		if ($this->roles->holdsDrafts($sourceGenerationId, $sourceDirHash))
		{
			$roles[] = MailboxDirectoryTable::TYPE_DRAFT;
		}

		foreach ($roles as $role)
		{
			foreach ($this->syncedTargets as $hash => $row)
			{
				$holds = $role === MailboxDirectoryTable::TYPE_DRAFT
					? $this->roles->holdsDrafts($this->preparedGenerationId, $hash)
					: $this->roles->holds($this->preparedGenerationId, $hash, $role)
				;

				if ($holds)
				{
					return self::destination($row, self::ORIGIN_ROLE, $role);
				}
			}
		}

		return null;
	}

	private function byPair(int $sourceGenerationId, string $sourceDirHash): ?array
	{
		$paired = $this->pairs->targetOf($sourceGenerationId, $sourceDirHash);
		$row = $paired !== null ? ($this->syncedTargets[$paired['hash']] ?? null) : null;

		if ($row === null)
		{
			return null;
		}

		return self::destination($row, $paired['created'] ? self::ORIGIN_PAIR_CREATED : self::ORIGIN_PAIR_FOUND);
	}

	/**
	 * The rules of pairing a folder found on the new server {@see FolderPairRules}, for the transfers
	 * that recorded no pair for it. A root folder is compared with the root folders only; more than
	 * one candidate is no answer, a letter is not put into a folder picked by chance.
	 */
	private function byPath(int $sourceGenerationId, string $sourceDirHash): ?array
	{
		$source = $this->folders[$sourceGenerationId][$sourceDirHash] ?? null;
		if ($source === null || (string)$source['PATH'] === '')
		{
			return null;
		}

		$isRoot = (int)($source['LEVEL'] ?? 0) <= 1;

		$candidates = [];
		foreach ($this->syncedTargets as $hash => $row)
		{
			if ($isRoot && (int)($row['LEVEL'] ?? 0) > 1)
			{
				continue;
			}

			$candidates[] = [
				'name' => (string)$row['PATH'],
				'delim' => (string)($row['DELIMITER'] ?? ''),
				'hash' => (string)$hash,
			];
		}

		$matches = FolderPairRules::matchingDirectories((string)$source['PATH'], $candidates, $isRoot);
		if (count($matches) !== 1)
		{
			return null;
		}

		return self::destination($this->syncedTargets[$matches[0]['hash']], self::ORIGIN_PATH_MATCH);
	}

	/**
	 * @return array{path: string, hash: string, origin: string, role: ?string}
	 */
	private static function destination(array $row, string $origin, ?string $role = null): array
	{
		return [
			'path' => (string)$row['PATH'],
			'hash' => (string)$row['DIR_MD5'],
			'origin' => $origin,
			'role' => $role,
		];
	}
}
