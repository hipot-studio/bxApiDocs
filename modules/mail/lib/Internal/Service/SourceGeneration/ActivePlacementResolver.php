<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Admission of a user command to the active source generation of a mailbox.
 *
 * The existence of a uid row or a folder in the database is not enough: the message the
 * command names and the folder it targets must belong to the mailbox AND to the generation
 * its ACTIVE_GENERATION_ID pointer refers to. A row of a retained generation still exists
 * physically but is read-only, so the command is rejected with MAIL_SOURCE_GENERATION_CHANGED
 * before any local change, queue entry or IMAP call.
 *
 * A missing entity is left to the caller: its own "not found" answer tells the user something
 * different from an instruction to refresh the page and retry.
 *
 * A mailbox without generations keeps the unfiltered scope, and every check passes.
 */
final class ActivePlacementResolver
{
	public const ERROR_GENERATION_CHANGED = 'MAIL_SOURCE_GENERATION_CHANGED';

	private const FOLDER_TYPES = [
		MailboxDirectoryTable::TYPE_INCOME,
		MailboxDirectoryTable::TYPE_OUTCOME,
		MailboxDirectoryTable::TYPE_DRAFT,
		MailboxDirectoryTable::TYPE_TRASH,
		MailboxDirectoryTable::TYPE_SPAM,
	];

	private readonly GenerationScope $scope;
	private ?bool $legacyGenerationIsActive = null;

	/**
	 * @var array<string, array<int|string>> The set of uid rows asked about => the foreign ones
	 *      among them. A command checks its placements and then filters them, which is the
	 *      same question twice.
	 */
	private array $foreignPlacements = [];

	public function __construct(
		private readonly int $mailboxId,
		?GenerationScope $scope = null,
	)
	{
		$this->scope = $scope ?? self::readActiveScope($mailboxId);
	}

	/**
	 * The pointer of the mailbox is asked of the database and not of the cache of this process: the
	 * admission of a command is the one place where a stale pointer lets a command of the previous
	 * source reach the new one. The switch drops the cache in the process that performs it, and this
	 * process may well have read the pointer before the switch happened at all.
	 */
	private static function readActiveScope(int $mailboxId): GenerationScope
	{
		GenerationScope::invalidateActivePointerCache($mailboxId);

		return GenerationScope::forMailbox($mailboxId);
	}

	public function getScope(): GenerationScope
	{
		return $this->scope;
	}

	public function getActiveGenerationId(): int
	{
		return $this->scope->getStampGenerationId();
	}

	/**
	 * Legacy generation 0 remains executable through the first-generation backfill because it
	 * only labels the existing physical source. A real source switch makes such commands stale.
	 */
	public function isActiveGeneration(int $generationId): bool
	{
		$activeGenerationId = $this->scope->getStampGenerationId();
		if ($activeGenerationId === $generationId)
		{
			return true;
		}

		return $generationId === 0
			&& $activeGenerationId > 0
			&& $this->isFirstGenerationBackfill($activeGenerationId);
	}

	private function isFirstGenerationBackfill(int $generationId): bool
	{
		return $this->legacyGenerationIsActive ??= (bool)MailboxSourceGenerationTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $generationId,
				'=MAILBOX_ID' => $this->mailboxId,
				'=OPERATION_ID' => MailboxSourceGenerationTable::OPERATION_G1_BACKFILL,
			],
			'limit' => 1,
		])->fetch();
	}

	/**
	 * The uid rows the command names. A mixed set is rejected as a whole: one row outside
	 * the active generation makes the whole command inadmissible.
	 *
	 * @param array<int|string> $uidIds
	 */
	public function checkPlacements(array $uidIds): Result
	{
		return $this->buildResult($this->findForeignPlacementIds($uidIds) !== []);
	}

	/**
	 * Uid rows of the mailbox that exist but sit outside its active generation.
	 *
	 * @param array<int|string> $uidIds
	 * @return array<int|string>
	 */
	private function findForeignPlacementIds(array $uidIds): array
	{
		if ($this->isUnfiltered() || empty($uidIds))
		{
			return [];
		}

		$key = $this->placementsKey($uidIds);

		if (array_key_exists($key, $this->foreignPlacements))
		{
			return $this->foreignPlacements[$key];
		}

		$rows = MailMessageUidTable::getList([
			'select' => ['ID', 'GENERATION_ID'],
			'filter' => [
				'=MAILBOX_ID' => $this->mailboxId,
				'@ID' => array_values(array_unique($uidIds)),
			],
		])->fetchAll();

		$foreign = [];
		foreach ($rows as $row)
		{
			if (!$this->scope->includes((int)$row['GENERATION_ID']))
			{
				$foreign[] = $row['ID'];
			}
		}

		return $this->foreignPlacements[$key] = $foreign;
	}

	/**
	 * @param array<int|string> $uidIds
	 */
	private function placementsKey(array $uidIds): string
	{
		$ids = array_map(strval(...), array_values(array_unique($uidIds)));
		sort($ids);

		return implode("\n", $ids);
	}

	/**
	 * Keeps the uid rows of the active generation. For rows injected past the repository
	 * lookup: a command must reach the active placements of a logical message only.
	 *
	 * @param array<array-key, array> $rows Uid rows with an ID key
	 * @return array<array-key, array>
	 */
	public function filterActivePlacements(array $rows): array
	{
		if ($this->isUnfiltered() || empty($rows))
		{
			return $rows;
		}

		$foreign = array_flip($this->findForeignPlacementIds(array_column($rows, 'ID')));

		return array_values(array_filter(
			$rows,
			static fn(array $row): bool => !isset($foreign[$row['ID']]),
		));
	}

	/**
	 * The target folder of a move, spam or trash command, addressed by its path. Call it
	 * when the folder was not found inside the active generation: the same path of a
	 * retained generation is a foreign folder, not a missing one.
	 */
	public function checkMissingFolderPath(?string $path): Result
	{
		if ((string)$path === '')
		{
			return new Result();
		}

		return $this->buildResult($this->folderExistsOutsideActiveGeneration([
			'=DIR_MD5' => md5((string)$path),
		]));
	}

	/**
	 * The same for a folder addressed by its system role (trash, spam, inbox).
	 */
	public function checkMissingFolderType(string $type): Result
	{
		if (!in_array($type, self::FOLDER_TYPES, true))
		{
			return new Result();
		}

		return $this->buildResult($this->folderExistsOutsideActiveGeneration([
			'=' . $type => MailboxDirectoryTable::ACTIVE,
		]));
	}

	private function folderExistsOutsideActiveGeneration(array $condition): bool
	{
		if ($this->isUnfiltered())
		{
			return false;
		}

		$rows = MailboxDirectoryTable::getList([
			'select' => ['ID', 'GENERATION_ID'],
			'filter' => ['=MAILBOX_ID' => $this->mailboxId] + $condition,
		])->fetchAll();

		$foundOutside = false;
		foreach ($rows as $row)
		{
			if ($this->scope->includes((int)$row['GENERATION_ID']))
			{
				return false;
			}

			$foundOutside = true;
		}

		return $foundOutside;
	}

	private function isUnfiltered(): bool
	{
		return $this->scope->isUnfiltered();
	}

	private function buildResult(bool $rejected): Result
	{
		$result = new Result();

		if (!$rejected)
		{
			return $result;
		}

		return $result->addError(new Error(
			Loc::getMessage('MAIL_SOURCE_GENERATION_CHANGED'),
			self::ERROR_GENERATION_CHANGED,
		));
	}
}
