<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Internal\Model\SyncRightsSnapshotTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * The access codes the root of an edit of rights carried before that edit, held for the session that
 * recalculates the materialized rights.
 *
 * The half before the edit is what makes the signal reach the one it is addressed to: a user whose access
 * was revoked holds no code of the state after the recalculation, so a record published to the current
 * codes alone would be idle on exactly the revocation the journal exists for.
 *
 * The snapshot belongs to one recalculation and leaves with it. An orphan - the snapshot of a
 * recalculation that was interrupted or hung - is taken by age instead, which is what IX_DISK_SRS_2 is
 * laid down for.
 */
final class SyncRightsSnapshotRepository
{
	/**
	 * The codes of the root are read where the writer reads them, so nothing here reaches for
	 * b_disk_simple_right on its own: what is captured before the edit is stored, and no more.
	 *
	 * The write is once per session. A repeat leaves the stored codes alone - by the time it happens the
	 * rights already hold the state after the edit, and an overwrite would store that as the state
	 * before it.
	 *
	 * @param string[] $codes Codes of the root as they stood before the edit.
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function createIfAbsent(int $setupSessionId, int $objectId, array $codes): AddResult
	{
		return SyncRightsSnapshotTable::addInsertIgnore([
			'SETUP_SESSION_ID' => $setupSessionId,
			'OBJECT_ID' => $objectId,
			'CODES' => $this->encodeCodes($codes),
			'CREATED_AT' => new DateTime(),
		]);
	}

	/**
	 * @return array{
	 *     ID: int,
	 *     SETUP_SESSION_ID: int,
	 *     OBJECT_ID: int,
	 *     CODES: string[],
	 *     CREATED_AT: DateTime
	 * }|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findBySetupSession(int $setupSessionId): ?array
	{
		$row = SyncRightsSnapshotTable::query()
			->setSelect(['*'])
			->where('SETUP_SESSION_ID', $setupSessionId)
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'SETUP_SESSION_ID' => (int)$row['SETUP_SESSION_ID'],
			'OBJECT_ID' => (int)$row['OBJECT_ID'],
			'CODES' => $this->decodeCodes($row['CODES'] ?? null),
			'CREATED_AT' => $row['CREATED_AT'],
		];
	}

	/**
	 * Which of the given recalculations left a snapshot behind, asked of a whole portion at once.
	 *
	 * The reconciliation of finished recalculations walks up the chain of forks looking for the session
	 * that holds the codes captured before the edit, and a chain is walked level by level rather than
	 * session by session: one statement per level of the chain instead of one per session of the portion.
	 *
	 * @param int[] $setupSessionIds
	 * @return array<int, true> the sessions among them that have a snapshot
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findSessionsWithSnapshot(array $setupSessionIds): array
	{
		$ids = array_values(array_unique(array_map('intval', $setupSessionIds)));
		if ($ids === [])
		{
			return [];
		}

		$rows = SyncRightsSnapshotTable::query()
			->setSelect(['SETUP_SESSION_ID'])
			->whereIn('SETUP_SESSION_ID', $ids)
			->fetchAll()
		;

		$found = [];
		foreach ($rows as $row)
		{
			$found[(int)$row['SETUP_SESSION_ID']] = true;
		}

		return $found;
	}

	/**
	 * The snapshot goes when its recalculation is over: the record it was needed for has been published
	 * by then, and keeping it would only give the reconciliation a session to write the same record for
	 * a second time.
	 *
	 * @throws SqlQueryException
	 */
	public function deleteBySetupSession(int $setupSessionId): void
	{
		Application::getConnection()->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# = ?i',
				SyncRightsSnapshotTable::getTableName(),
				'SETUP_SESSION_ID',
				$setupSessionId,
			))->compile()
		);
	}

	/**
	 * Orphans, in portions. A recalculation that never reached its end leaves a snapshot nothing else
	 * marks as abandoned, and by the audit such a recalculation is a matter of hours - so age is the only
	 * signal, and IX_DISK_SRS_2 covers exactly this predicate.
	 *
	 * The keys are read before the deletion because a portion cannot be expressed as a limit on the
	 * deletion itself in both supported databases.
	 *
	 * @return int Number of snapshots removed.
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SqlQueryException
	 * @throws SystemException
	 */
	public function deleteOlderThan(DateTime $createdBefore, int $limit): int
	{
		$rows = SyncRightsSnapshotTable::query()
			->setSelect(['ID'])
			->where('CREATED_AT', '<', $createdBefore)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		if (!$rows)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# IN (?@)',
				SyncRightsSnapshotTable::getTableName(),
				'ID',
				array_map(static fn (array $row): int => (int)$row['ID'], $rows),
			))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * The writer takes a flat list of codes, so the snapshot gives back exactly that and nothing shaped
	 * around it.
	 *
	 * @param string[] $codes
	 * @throws ArgumentException
	 */
	private function encodeCodes(array $codes): string
	{
		return Json::encode($this->normalizeCodes($codes));
	}

	/**
	 * @return string[]
	 * @throws ArgumentException
	 */
	private function decodeCodes(?string $codes): array
	{
		if ($codes === null || $codes === '')
		{
			return [];
		}

		return array_map('strval', Json::decode($codes));
	}

	/**
	 * @param string[] $codes
	 * @return string[]
	 */
	private function normalizeCodes(array $codes): array
	{
		$normalized = array_filter(
			array_unique(array_map('strval', $codes)),
			static fn (string $code): bool => $code !== '',
		);

		return array_values($normalized);
	}
}
