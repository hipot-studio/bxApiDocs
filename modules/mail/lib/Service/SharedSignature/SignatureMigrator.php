<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Type\DateTime;

/**
 * Copies personal signatures from the legacy table into the unified model (P7.T2).
 *
 * The owner comes from the legacy row, the scope is 'owner', the binding to a sender becomes an
 * assignment with a string target; an empty binding yields no assignment at all, which is how the
 * unified model spells "for every sender of the owner". Legacy rows are copied, never moved: until
 * the clean-up release they stay as the safety net against a defect in the copying itself.
 *
 * Idempotency is provided by the unique index over LEGACY_ID, not by a "migrated already?" check:
 * between such a check and the insert a second process manages to insert — two tabs of the same
 * user, or the user together with the background stepper. The losing insert is swallowed silently.
 * The pre-check this class does perform is a pure optimisation, so that opening the signature list
 * of a migrated user costs one SELECT instead of a failing INSERT per row.
 *
 * A remembered choice travels with the rows it points at: the copy has an identifier of its own,
 * and the link to the source row is what tells which copy a former identifier became.
 *
 * One row is copied in one transaction, a whole owner is not: the background stepper reaches the
 * rows of one owner in different portions anyway, so a half-copied owner is a normal state and not
 * an error to be rolled back. It is safe because the compatibility adapter reads the copies and the
 * rows not copied yet together — a copy that stayed after a failure in the middle is progress, and
 * the rest is picked up by the next run. What the next run does not repeat is the remapping of the
 * remembered choices, since it no longer sees the rows already copied — so a broken walk remaps the
 * choices of everything it did copy before giving up.
 *
 * The migration never changes which signature gets substituted: the sender strings are carried
 * over as they are, and the assignment dates of the owner scope play no part in the resolution
 * order (only the shared scope is ordered by them).
 */
class SignatureMigrator
{
	/**
	 * Option remembering the legacy row up to which everything has a copy. Below that mark the
	 * compatibility adapter has nothing to look for in the legacy table; above it — and until the
	 * mark appears at all — it reads the legacy rows of the owner next to the copies, because one
	 * owner may well be copied in parts.
	 */
	public const COPIED_UP_TO_OPTION = 'signature_migration_copied_up_to';

	/** Fields of the legacy row the migration needs. */
	private const LEGACY_FIELDS = ['ID', 'USER_ID', 'SENDER', 'SIGNATURE'];

	private SignatureChoiceStorage $choiceStorage;

	public function __construct(?SignatureChoiceStorage $choiceStorage = null)
	{
		$this->choiceStorage = $choiceStorage ?? new SignatureChoiceStorage();
	}

	/**
	 * Copies every not yet copied legacy row of the given user into the unified model.
	 * Bounded by the rows of that user: nobody else's data is touched.
	 *
	 * This is the access-time migration, so it runs in front of what the user actually asked for
	 * and must not be able to break it: a portal whose schema is not updated yet keeps working
	 * through the legacy table, the same way the compatibility adapter does.
	 *
	 * @return int Number of rows copied by this call.
	 */
	public function migrateUser(int $userId): int
	{
		if ($userId <= 0)
		{
			return 0;
		}

		try
		{
			return $this->migrateRows($this->readLegacyRows(['=USER_ID' => $userId]));
		}
		catch (\Throwable)
		{
			return 0;
		}
	}

	/**
	 * Copies a portion of the legacy table: rows with ID in ($afterId, $upToId]. Serves the
	 * background stepper, which fixes the upper bound once and walks the table by the cursor.
	 *
	 * @return array{lastId: int, processed: int, migrated: int} lastId equals $afterId when the
	 *         portion turned out to be empty.
	 */
	public function migrateRange(int $afterId, int $upToId, int $limit): array
	{
		$rows = $this->readLegacyRows(
			['>ID' => $afterId, '<=ID' => $upToId],
			$limit > 0 ? $limit : 1,
		);

		$lastId = $afterId;
		foreach ($rows as $row)
		{
			$lastId = max($lastId, (int)$row['ID']);
		}

		return [
			'lastId' => $lastId,
			'processed' => count($rows),
			'migrated' => $this->migrateRows($rows),
		];
	}

	/**
	 * Identifier the given legacy row has in the unified model, or null when it has not been
	 * copied yet. Lets a client that still holds a legacy identifier — a page rendered before the
	 * migration — keep addressing its own signature.
	 */
	public function findMigratedId(int $legacyId, int $ownerId): ?int
	{
		if ($legacyId <= 0 || $ownerId <= 0)
		{
			return null;
		}

		try
		{
			$row = SharedSignatureTable::getList([
				'select' => ['ID'],
				'filter' => [
					'=LEGACY_ID' => $legacyId,
					'=OWNER_ID' => $ownerId,
					'=SCOPE' => SharedSignatureTable::SCOPE_OWNER,
				],
				'limit' => 1,
			])->fetch();
		}
		catch (\Throwable)
		{
			// A portal whose schema is not updated yet knows no link at all.
			return null;
		}

		return $row ? (int)$row['ID'] : null;
	}

	/**
	 * The legacy row a signature was copied from, or 0 when there is none.
	 */
	public function findLegacySourceId(int $signatureId): int
	{
		if ($signatureId <= 0)
		{
			return 0;
		}

		try
		{
			$row = SharedSignatureTable::getList([
				'select' => ['LEGACY_ID'],
				'filter' => ['=ID' => $signatureId],
				'limit' => 1,
			])->fetch();
		}
		catch (\Throwable)
		{
			return 0;
		}

		return $row ? (int)($row['LEGACY_ID'] ?? 0) : 0;
	}

	/**
	 * Number of legacy rows that have no copy in the unified model — the answer to "how much is
	 * left", which is the condition for starting the clean-up release. Zero plus a finished
	 * stepper means the migration is over.
	 */
	public static function countPending(): int
	{
		$legacyTable = UserSignatureTable::getTableName();
		$unifiedTable = SharedSignatureTable::getTableName();

		$sql = 'SELECT COUNT(1) AS PENDING FROM ' . $legacyTable . ' L'
			. ' LEFT JOIN ' . $unifiedTable . ' S ON S.LEGACY_ID = L.ID'
			. ' WHERE S.ID IS NULL'
		;

		$row = Application::getConnection()->query($sql)->fetch() ?: [];

		// PostgreSQL folds the alias to lower case.
		return (int)($row['PENDING'] ?? $row['pending'] ?? 0);
	}

	/**
	 * Legacy row up to which every row has a copy, 0 when nothing has been confirmed yet. A
	 * remembered answer: counting is a join over the whole legacy table, and the question is asked
	 * on the read path of the personal signatures.
	 */
	public static function copiedUpTo(): int
	{
		return (int)Option::get('mail', self::COPIED_UP_TO_OPTION, '0');
	}

	/**
	 * Remembers the border everything below which is copied — but only when that is really so. The
	 * background stepper calls it when its walk ends.
	 *
	 * The border is read before the counting on purpose: a row inserted in between gets an
	 * identifier above the border and stays visible to the compatibility adapter. A border remembered
	 * the other way round could hide a row that has no copy, which is exactly what it is there to
	 * prevent. The border never moves back either — a lower one only means a later walk found
	 * something left.
	 *
	 * @return bool Whether nothing is left to copy.
	 */
	public static function confirmCompletion(): bool
	{
		$border = self::maxLegacyId();

		if (self::countPending() > 0)
		{
			return false;
		}

		if ($border > self::copiedUpTo())
		{
			Option::set('mail', self::COPIED_UP_TO_OPTION, (string)$border);
		}

		return true;
	}

	/**
	 * Identifier of the last row of the legacy table, 0 when it is empty.
	 */
	public static function maxLegacyId(): int
	{
		$row = UserSignatureTable::getLegacyList([
			'select' => ['ID'],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		return (int)($row['ID'] ?? 0);
	}

	/**
	 * Removes the legacy row a signature was copied from. Called when the signature itself is
	 * deleted: the legacy row survives the migration on purpose, but an orphaned one would be
	 * copied again on the next access and the deleted signature would resurrect.
	 */
	public function dropLegacySource(int $legacyId): void
	{
		if ($legacyId <= 0 || !$this->legacyRowExists($legacyId))
		{
			return;
		}

		UserSignatureTable::delete($legacyId);
	}

	// -------------------------------------------------------------------------
	// Migration internals
	// -------------------------------------------------------------------------

	/**
	 * @param array<array<string, mixed>> $rows Legacy rows.
	 * @return int Number of rows copied.
	 */
	private function migrateRows(array $rows): int
	{
		if (empty($rows))
		{
			return 0;
		}

		$copied = $this->readCopyIds(array_map(static fn(array $row): int => (int)$row['ID'], $rows));

		$migrated = 0;
		$owners = [];

		try
		{
			foreach ($rows as $row)
			{
				if (isset($copied[(int)$row['ID']]))
				{
					continue;
				}

				if ($this->migrateRow($row))
				{
					++$migrated;
					$owners[(int)$row['ID']] = (int)$row['USER_ID'];
				}
			}
		}
		finally
		{
			// The rows copied before a failure in the middle get their choices remapped all the same:
			// the next run starts by filtering out everything already copied, so it would never reach
			// them again and the choice would keep pointing at a row nothing can resolve.
			$this->remapChoices($owners);
		}

		return $migrated;
	}

	/**
	 * Points the remembered choices at the copies. A choice stores an identifier, and until the
	 * migration that identifier belongs to the legacy table — so a choice left as it is would
	 * either resolve to nothing or, since the two identifier spaces overlap, to a foreign row.
	 * The link the copy carries to its source is the exact correspondence; a "personal or shared"
	 * flag would not tell the two spaces apart, both rows are personal.
	 *
	 * @param array<int, int> $owners Legacy row ID → its owner, for the rows just copied.
	 */
	private function remapChoices(array $owners): void
	{
		if (empty($owners))
		{
			return;
		}

		$mapByOwner = [];
		foreach ($this->readCopyIds(array_keys($owners)) as $legacyId => $signatureId)
		{
			$ownerId = $owners[$legacyId] ?? 0;
			if ($ownerId > 0)
			{
				$mapByOwner[$ownerId][$legacyId] = $signatureId;
			}
		}

		foreach ($mapByOwner as $ownerId => $map)
		{
			$this->choiceStorage->remapIds($ownerId, $map);
		}
	}

	/**
	 * Copies of the given legacy rows: serves both the pre-check before the migration and the
	 * mapping of the remembered choices after it.
	 *
	 * @param int[] $legacyIds
	 * @return array<int, int> Legacy row ID → identifier of its copy.
	 */
	private function readCopyIds(array $legacyIds): array
	{
		if (empty($legacyIds))
		{
			return [];
		}

		$ids = [];
		$result = SharedSignatureTable::getList([
			'select' => ['ID', 'LEGACY_ID'],
			'filter' => ['=LEGACY_ID' => $legacyIds],
		]);
		while ($row = $result->fetch())
		{
			$ids[(int)$row['LEGACY_ID']] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * Copies one legacy row. The signature and its assignment are written in one transaction, so
	 * a signature bound to a sender never appears without its binding — that would silently turn
	 * it into the general one and change which signature gets substituted.
	 *
	 * @param array<string, mixed> $row
	 * @return bool false when the row was copied by somebody else in the meantime.
	 */
	protected function migrateRow(array $row): bool
	{
		$legacyId = (int)($row['ID'] ?? 0);
		$ownerId = (int)($row['USER_ID'] ?? 0);

		if ($legacyId <= 0 || $ownerId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$addResult = SharedSignatureTable::add([
				'CREATED_BY' => $ownerId,
				'OWNER_ID' => $ownerId,
				'SCOPE' => SharedSignatureTable::SCOPE_OWNER,
				'SIGNATURE' => (string)($row['SIGNATURE'] ?? ''),
				'LEGACY_ID' => $legacyId,
				'DATE_CREATE' => new DateTime(),
				'DATE_MODIFY' => new DateTime(),
			]);

			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return false;
			}

			$sender = (string)($row['SENDER'] ?? '');
			if ($sender !== '' && !$this->addSenderAssignment((int)$addResult->getId(), $sender))
			{
				$connection->rollbackTransaction();

				return false;
			}

			$connection->commitTransaction();

			return true;
		}
		catch (DuplicateEntryException)
		{
			// The unique index over LEGACY_ID did its job: another process — the other tab of the
			// same user, or the background stepper — copied this row first.
			$connection->rollbackTransaction();

			return false;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}

	private function addSenderAssignment(int $signatureId, string $sender): bool
	{
		return SharedSignatureAssignmentTable::add([
			'SIGNATURE_ID' => $signatureId,
			'TARGET_TYPE' => SharedSignatureAssignmentTable::TARGET_SENDER,
			'TARGET_ID' => 0,
			'TARGET_VALUE' => $sender,
			'IS_FLAT' => 'N',
			'DATE_CREATE' => new DateTime(),
		])->isSuccess();
	}

	// -------------------------------------------------------------------------
	// Legacy table access
	// -------------------------------------------------------------------------

	/**
	 * @return array<array<string, mixed>>
	 */
	private function readLegacyRows(array $filter, ?int $limit = null): array
	{
		$parameters = [
			'select' => self::LEGACY_FIELDS,
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		];

		if ($limit !== null)
		{
			$parameters['limit'] = $limit;
		}

		$rows = [];
		$result = UserSignatureTable::getLegacyList($parameters);
		while ($row = $result->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function readLegacyRow(int $legacyId): ?array
	{
		$row = UserSignatureTable::getLegacyList([
			'select' => self::LEGACY_FIELDS,
			'filter' => ['=ID' => $legacyId],
			'limit' => 1,
		])->fetch();

		return $row ?: null;
	}

	private function legacyRowExists(int $legacyId): bool
	{
		return $this->readLegacyRow($legacyId) !== null;
	}
}
