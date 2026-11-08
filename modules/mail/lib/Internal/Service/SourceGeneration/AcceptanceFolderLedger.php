<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

/**
 * The folders of the user an acceptance of a snapshot created on the target server before the
 * operation had its generation written.
 *
 * The creation runs before the mailbox is captured, so an acceptance refused afterwards - the mailbox
 * busy with a synchronization, a conflict, a lost answer of the server - leaves the folders there with
 * nothing to remember them by.
 * Its repeat finds them as ordinary folders of the target server, and without this ledger the prepared
 * generation would get no pairs for them {@see MigrationService::STATE_FOLDER_PAIRS}: their letters
 * would go to the inbox. The ledger is kept per mailbox for one operation and dropped once the
 * generation holds the pairs.
 */
class AcceptanceFolderLedger
{
	/**
	 * Far above the folders of a real mailbox. The value lives in b_option.VALUE (MEDIUMTEXT on
	 * MySQL, TEXT on PostgreSQL) and the core loads every value of the category into its cache at
	 * once, so the ledger of one mailbox is kept small rather than bounded by the column alone.
	 */
	public const MAX_ENTRIES = 1000;
	/** The encoded value, in bytes */
	public const MAX_LENGTH = 512 * 1024;

	private const OPTION_CATEGORY = 'mail.migration_created_folders';

	/**
	 * @param array{pairs?: array, notCreated?: array} $folderOutcomes What this acceptance brought
	 *        into the target server {@see MailboxMigrationSwitcher::RESULT_FOLDER_OUTCOMES}.
	 * @param array<int, array<string, mixed>> $imapDirectories The target folders this acceptance resolved.
	 * @return array{pairs: array, notCreated: array} The same outcomes with the pairs an earlier attempt
	 *         of the operation created {@see record()} and this one found as they were.
	 */
	public function carryOver(
		int $mailboxId,
		string $operationId,
		array $folderOutcomes,
		array $imapDirectories,
	): array
	{
		$pairs = array_values((array)($folderOutcomes['pairs'] ?? []));
		$ledger = $this->read($mailboxId, $operationId);

		$paired = array_fill_keys(array_map(static fn (array $pair): string => (string)$pair['source'], $pairs), true);
		$taken = array_fill_keys(array_map(static fn (array $pair): string => (string)$pair['target'], $pairs), true);
		foreach ($ledger as $source => $target)
		{
			$available = array_values(array_filter(
				$imapDirectories,
				static fn (array $directory): bool => !isset($taken[(string)($directory['name'] ?? '')]),
			));
			$matches = FolderPairRules::matchingDirectories($target, $available, false);
			if ($matches === [])
			{
				$roots = array_values(array_filter($available, static function (array $directory): bool {
					$name = (string)($directory['name'] ?? '');
					$delimiter = (string)($directory['delim'] ?? '');

					return $delimiter === ''
						|| mb_strtoupper($delimiter) === 'NIL'
						|| !str_contains($name, $delimiter);
				}));
				$matches = FolderPairRules::matchingDirectories($target, $roots, true);
			}
			$match = reset($matches);
			$found = count($matches) === 1 ? (string)($match['name'] ?? '') : null;
			if (!isset($paired[$source]) && $found !== null && !isset($taken[$found]))
			{
				$pairs[] = ['source' => (string)$source, 'target' => $found, 'created' => true];
				$taken[$found] = true;
			}
		}

		return ['pairs' => $pairs, 'notCreated' => array_values((array)($folderOutcomes['notCreated'] ?? []))];
	}

	/**
	 * Writes a folder down before its CREATE goes out: the answer of the CREATE or the lookup
	 * confirming it may be lost after the server made the folder. An entry whose folder never
	 * appeared pairs nothing {@see carryOver()}.
	 *
	 * The read and the write are not atomic on their own: the only caller is the acceptance of a
	 * snapshot, which holds the {@see OperationLock} of the mailbox the whole time, and so does the
	 * {@see forget()} after it. A second acceptance of the same mailbox is refused at that lock
	 * before it creates or records anything.
	 *
	 * @return bool False when the ledger is full: the entry is not written, the ones already there
	 *         stay, and the folder must not be created, since nothing would remember it.
	 */
	public function record(int $mailboxId, string $operationId, string $sourcePath, string $targetName): bool
	{
		$ledger = $this->read($mailboxId, $operationId);
		if (($ledger[$sourcePath] ?? null) === $targetName)
		{
			return true;
		}

		$ledger[$sourcePath] = $targetName;
		$encoded = $this->encode($operationId, $ledger);
		if (count($ledger) > self::MAX_ENTRIES || strlen($encoded) > self::MAX_LENGTH)
		{
			$this->journal(
				'acceptance_ledger_full',
				$mailboxId,
				$operationId,
				sprintf('%u entries, %u bytes', count($ledger), strlen($encoded)),
			);

			return false;
		}

		Option::set(self::OPTION_CATEGORY, (string)$mailboxId, $encoded);

		return true;
	}

	public function forget(int $mailboxId): void
	{
		Option::delete(self::OPTION_CATEGORY, ['name' => (string)$mailboxId]);
	}

	/**
	 * @return array<string, string> Source path => target name, for this operation only.
	 */
	private function read(int $mailboxId, string $operationId): array
	{
		$stored = Option::get(self::OPTION_CATEGORY, (string)$mailboxId, '');
		if ($stored === '')
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($stored);
		}
		catch (\Throwable)
		{
			$decoded = null;
		}

		if (!is_array($decoded) || !is_string($decoded['operation'] ?? null) || !is_array($decoded['pairs'] ?? null))
		{
			// Only the server writes it, so this is a damaged value, not an empty ledger: its pairs are lost
			$this->journal('acceptance_ledger_unreadable', $mailboxId, $operationId, sprintf('%u bytes', strlen($stored)));

			return [];
		}

		if ($decoded['operation'] !== $operationId)
		{
			return [];
		}

		return array_map('strval', array_filter($decoded['pairs'], 'is_string'));
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private function encode(string $operationId, array $pairs): string
	{
		return Json::encode(['operation' => $operationId, 'pairs' => $pairs]);
	}

	protected function journal(string $event, int $mailboxId, string $operationId, string $detail): void
	{
		MigrationMetrics::journalOperation($event, $mailboxId, $operationId, 0, null, $detail);
	}
}
