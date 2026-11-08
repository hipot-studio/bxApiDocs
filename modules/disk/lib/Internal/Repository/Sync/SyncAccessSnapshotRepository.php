<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Internal\Model\SyncAccessSnapshotTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * Access codes a pair of a user and an application was last told about.
 *
 * The snapshot belongs to the pair and outlives its sessions: a narrowing that happened between the
 * last read and a new session is only visible while the previous set of codes is still stored.
 */
final class SyncAccessSnapshotRepository
{
	private const FIRST_REVISION = 0;

	/**
	 * @return array{
	 *     ID: int,
	 *     USER_ID: int,
	 *     APP_ID: string,
	 *     REVISION: int,
	 *     CODES_HASH: string,
	 *     CODES: string[],
	 *     UPDATED_AT: DateTime
	 * }|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findByPair(int $userId, string $appId): ?array
	{
		$row = SyncAccessSnapshotTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->where('APP_ID', $appId)
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		return [
			'ID' => (int)$row['ID'],
			'USER_ID' => (int)$row['USER_ID'],
			'APP_ID' => (string)$row['APP_ID'],
			'REVISION' => (int)$row['REVISION'],
			'CODES_HASH' => (string)$row['CODES_HASH'],
			'CODES' => $this->decodeCodes($row['CODES'] ?? null),
			'UPDATED_AT' => $row['UPDATED_AT'],
		];
	}

	/**
	 * The baseline of a pair is written once. An insert that loses the race to a parallel first read
	 * leaves the winner alone: overwriting would reset a revision that has already been handed out.
	 *
	 * @param string[] $codes
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function createIfAbsent(int $userId, string $appId, array $codes): AddResult
	{
		return SyncAccessSnapshotTable::addInsertIgnore([
			'USER_ID' => $userId,
			'APP_ID' => $appId,
			'REVISION' => self::FIRST_REVISION,
			'CODES_HASH' => $this->hashCodes($codes),
			'CODES' => $this->encodeCodes($codes),
			'UPDATED_AT' => new DateTime(),
		]);
	}

	/**
	 * Compare and set on the revision: the row is rewritten only while it still holds the revision the
	 * caller has read. Parallel reads that saw the same divergence therefore publish it once - the loser
	 * gets false and has nothing left to report.
	 *
	 * The revision always changes, so the count of affected rows tells a won transition from a lost one
	 * on both supported databases.
	 *
	 * @param string[] $codes
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 */
	public function updateIfRevisionMatches(int $userId, string $appId, int $expectedRevision, array $codes): bool
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = SyncAccessSnapshotTable::getTableName();

		[$assignments, $binds] = $sqlHelper->prepareUpdate($tableName, [
			'REVISION' => $expectedRevision + 1,
			'CODES_HASH' => $this->hashCodes($codes),
			'CODES' => $this->encodeCodes($codes),
			'UPDATED_AT' => new DateTime(),
		]);

		$condition = (new SqlExpression(
			'?# = ?i AND ?# = ?s AND ?# = ?i',
			'USER_ID',
			$userId,
			'APP_ID',
			$appId,
			'REVISION',
			$expectedRevision,
		))->compile();

		$connection->queryExecute(
			'UPDATE ' . $sqlHelper->quote($tableName) . ' SET ' . $assignments . ' WHERE ' . $condition,
			$binds,
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Snapshots that have not moved since the boundary, one portion of them, read through IX_DISK_SAS_2.
	 *
	 * Candidates and not victims: the stamp of the row moves only when the codes of the pair diverge
	 * ({@see \Bitrix\Disk\Internal\Service\Sync\AccessCodeSnapshotService::compareWithSnapshot()}), so a
	 * pair polling for a year with a stable set of codes carries a stamp as old as its first session.
	 * Whether the pair is still working is answered by its session and never by this column.
	 *
	 * The keyset is carried because a candidate the caller decides to keep stays where it is: without it
	 * the same kept rows would be read at the head of the stream for the whole of the budget.
	 *
	 * @return array<int, array{ID: int, USER_ID: int, APP_ID: string, UPDATED_AT: DateTime}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findAgedCandidates(
		DateTime $updatedBefore,
		?DateTime $afterUpdatedAt,
		int $afterId,
		int $limit,
	): array
	{
		$query = SyncAccessSnapshotTable::query()
			->setSelect(['ID', 'USER_ID', 'APP_ID', 'UPDATED_AT'])
			->where('UPDATED_AT', '<', $updatedBefore)
			->setOrder(['UPDATED_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
		;

		if ($afterUpdatedAt !== null)
		{
			// The range on the key column stays a range so that the index still serves the ordering; the
			// tie on the identifier is a residual condition over the few rows sharing a second.
			$query
				->where('UPDATED_AT', '>=', $afterUpdatedAt)
				->where(
					Query::filter()
						->logic('or')
						->where('UPDATED_AT', '>', $afterUpdatedAt)
						->where('ID', '>', $afterId)
					,
				)
			;
		}

		return array_map(
			static fn (array $row): array => [
				'ID' => (int)$row['ID'],
				'USER_ID' => (int)$row['USER_ID'],
				'APP_ID' => (string)$row['APP_ID'],
				'UPDATED_AT' => $row['UPDATED_AT'],
			],
			$query->fetchAll(),
		);
	}

	/**
	 * The snapshots of a portion, taken out by their keys and by the age they were chosen for.
	 *
	 * The age is repeated here because the candidates are read under a lock and deleted outside it, and
	 * the stamp of a candidate moves the moment the codes of the pair diverge. A baseline that moved in
	 * between is the baseline the next narrowing will be reported against, and dropping it would leave
	 * that narrowing unreported - the row would be laid down again by the next call of the pair, silently
	 * ({@see \Bitrix\Disk\Internal\Service\Sync\AccessCodeSnapshotService::compareWithSnapshot()}).
	 *
	 * @param int[] $ids
	 * @return int Number of snapshots removed.
	 * @throws SqlQueryException
	 */
	public function deleteByIds(array $ids, DateTime $updatedBefore): int
	{
		if ($ids === [])
		{
			return 0;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# IN (?@) AND ?# < ?',
				SyncAccessSnapshotTable::getTableName(),
				'ID',
				$ids,
				'UPDATED_AT',
				$updatedBefore,
			))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * Codes are a set: the same set written in another order is the same snapshot, and the stored hash
	 * lets a caller tell a change from a repetition without reading the list itself.
	 *
	 * @param string[] $codes
	 */
	public function hashCodes(array $codes): string
	{
		return hash('sha256', implode(',', $this->normalizeCodes($codes)));
	}

	/**
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
		$normalized = array_unique(array_map('strval', $codes));
		sort($normalized, SORT_STRING);

		return $normalized;
	}
}
