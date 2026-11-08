<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Internal\Model\SyncSessionTable;
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
 * Server state of the synchronization cycle of a pair of a user and an application.
 *
 * The list of visible storages and the checkpoints in storages of other people are stored one column
 * each and are encoded here and nowhere else, so that callers deal with identifiers and never with the
 * storage format.
 */
final class SyncSessionRepository
{
	/**
	 * A pair has one live session: the new one takes the place of the previous, which is what stops the
	 * snapshot token of the previous cycle from being accepted. The snapshot of access codes is a row of
	 * its own in a table of its own and is not touched here.
	 *
	 * @param int[] $storageIds Storages visible to the pair within this cycle.
	 * @param array<int, int[]> $checkpointsByStorage Checkpoints of visibility in storages of other
	 *                                               people, grouped by storage. A pair holding none is
	 *                                               the ordinary case, so the grouping may be empty.
	 */
	public function replaceForPair(
		int $userId,
		string $appId,
		DateTime $snapshotTokenExpiresAt,
		DateTime $boundModifiedAt,
		DateTime $boundInteractedAt,
		int $effectiveDepthDays,
		int $plannedPollInterval,
		array $storageIds,
		array $checkpointsByStorage = [],
	): AddResult
	{
		return SyncSessionTable::addMerge([
			'USER_ID' => $userId,
			'APP_ID' => $appId,
			'CREATED_AT' => new DateTime(),
			'SNAPSHOT_TOKEN_EXPIRES_AT' => $snapshotTokenExpiresAt,
			'BOUND_MODIFIED_AT' => $boundModifiedAt,
			'BOUND_INTERACTED_AT' => $boundInteractedAt,
			'EFFECTIVE_DEPTH_DAYS' => $effectiveDepthDays,
			'PLANNED_POLL_INTERVAL' => $plannedPollInterval,
			'STORAGE_IDS' => $this->encodeStorageIds($storageIds),
			'CHECKPOINTS_BY_STORAGE' => $this->encodeCheckpointsByStorage($checkpointsByStorage),
		]);
	}

	/**
	 * @return array{
	 *     ID: int,
	 *     USER_ID: int,
	 *     APP_ID: string,
	 *     CREATED_AT: DateTime,
	 *     SNAPSHOT_TOKEN_EXPIRES_AT: DateTime,
	 *     BOUND_MODIFIED_AT: DateTime,
	 *     BOUND_INTERACTED_AT: DateTime,
	 *     EFFECTIVE_DEPTH_DAYS: int,
	 *     PLANNED_POLL_INTERVAL: int,
	 *     STORAGE_IDS: int[],
	 *     CHECKPOINTS_BY_STORAGE: array<int, int[]>
	 * }|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findByPair(int $userId, string $appId): ?array
	{
		$row = SyncSessionTable::query()
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
			'CREATED_AT' => $row['CREATED_AT'],
			'SNAPSHOT_TOKEN_EXPIRES_AT' => $row['SNAPSHOT_TOKEN_EXPIRES_AT'],
			'BOUND_MODIFIED_AT' => $row['BOUND_MODIFIED_AT'],
			'BOUND_INTERACTED_AT' => $row['BOUND_INTERACTED_AT'],
			'EFFECTIVE_DEPTH_DAYS' => (int)$row['EFFECTIVE_DEPTH_DAYS'],
			'PLANNED_POLL_INTERVAL' => (int)$row['PLANNED_POLL_INTERVAL'],
			'STORAGE_IDS' => $this->decodeStorageIds($row['STORAGE_IDS'] ?? null),
			'CHECKPOINTS_BY_STORAGE' => $this->decodeCheckpointsByStorage($row['CHECKPOINTS_BY_STORAGE'] ?? null),
		];
	}

	/**
	 * The orm has no delete by filter, and reading the keys first would cost one statement per expired
	 * session; IX_DISK_SS_2 covers exactly this predicate.
	 *
	 * @return int Number of sessions removed.
	 * @throws SqlQueryException
	 */
	public function deleteExpired(DateTime $expiredBefore): int
	{
		$connection = Application::getConnection();

		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# < ?',
				SyncSessionTable::getTableName(),
				'SNAPSHOT_TOKEN_EXPIRES_AT',
				$expiredBefore,
			))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * The oldest sessions past the retention of the journal, one portion of them.
	 *
	 * The age of the row is the predicate and not the expiry of its fetch token, because those two
	 * answer different questions. The token of the initial fetch lives its own days; the cursor of the
	 * cycle lives the retention counted from the boundary of the session, which is earlier than the row
	 * itself ({@see \Bitrix\Disk\Internal\Service\Sync\SessionService::create()}). So a row created
	 * longer ago than the retention cannot have a live cursor behind it, while a row whose token has
	 * merely run out very well can - and it is that row which says the pair is still working.
	 *
	 * The row is replaced rather than added on every new session of the pair, so the stamp read here is
	 * the moment of the latest session and not of the first one.
	 *
	 * Read through IX_DISK_SS_3, which is what the order is for: the stream is ordered by the very column
	 * the portion is chosen by, broken out of ties by the identifier, so the page comes off the key
	 * instead of being sorted in memory. The order of the identifiers alone would not do it - the row is
	 * rewritten in place on every new session, so a pair working for years carries a small identifier and
	 * a fresh stamp.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findIdsCreatedBefore(DateTime $createdBefore, int $limit): array
	{
		$rows = SyncSessionTable::query()
			->setSelect(['ID'])
			->where('CREATED_AT', '<', $createdBefore)
			->setOrder(['CREATED_AT' => 'ASC', 'ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * The pairs of the given users whose session is younger than the boundary - the pairs that can still
	 * hold a live cursor.
	 *
	 * Asked about a batch of users rather than a pair at a time: the caller weighs a portion of rows of
	 * another table against this one, and a question per row would be a query per row.
	 *
	 * @param int[] $userIds
	 * @return array<int, array{USER_ID: int, APP_ID: string}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findPairsCreatedSince(array $userIds, DateTime $createdSince): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = SyncSessionTable::query()
			->setSelect(['USER_ID', 'APP_ID'])
			->whereIn('USER_ID', $userIds)
			->where('CREATED_AT', '>=', $createdSince)
			->fetchAll()
		;

		return array_map(
			static fn (array $row): array => [
				'USER_ID' => (int)$row['USER_ID'],
				'APP_ID' => (string)$row['APP_ID'],
			],
			$rows,
		);
	}

	/**
	 * The sessions of a portion, taken out by their keys and by the age they were chosen for.
	 *
	 * The age is repeated here and not left to the reading of the portion, because the two are not one
	 * statement: the identifiers are taken under a lock and the deletion runs outside it. A pair that
	 * opens a session in between keeps its identifier - the row is rewritten in place ({@see
	 * replaceForPair()}) - so a deletion addressed to identifiers alone would take away a session whose
	 * snapshot token has just been handed out, and the first page of the initial fetch would answer that
	 * the token has expired.
	 *
	 * @param int[] $ids
	 * @return int Number of sessions removed.
	 * @throws SqlQueryException
	 */
	public function deleteByIds(array $ids, DateTime $createdBefore): int
	{
		if ($ids === [])
		{
			return 0;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# IN (?@) AND ?# < ?',
				SyncSessionTable::getTableName(),
				'ID',
				$ids,
				'CREATED_AT',
				$createdBefore,
			))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * @param int[] $storageIds
	 * @throws ArgumentException
	 */
	private function encodeStorageIds(array $storageIds): string
	{
		return Json::encode(array_values(array_unique(array_map('intval', $storageIds))));
	}

	/**
	 * @return int[]
	 * @throws ArgumentException
	 */
	private function decodeStorageIds(?string $storageIds): array
	{
		if ($storageIds === null || $storageIds === '')
		{
			return [];
		}

		return array_map('intval', Json::decode($storageIds));
	}

	/**
	 * A storage without checkpoints is dropped rather than written empty: it would arrive as a unit to
	 * merge with nothing narrowing the walk inside it, and the whole storage of another person would be
	 * read. The grouping is cast to an object so that the identifiers of the storages stay the keys of
	 * the string and cannot be encoded away as the positions of a list.
	 *
	 * @param array<int, int[]> $checkpointsByStorage
	 * @throws ArgumentException
	 */
	private function encodeCheckpointsByStorage(array $checkpointsByStorage): string
	{
		$grouped = [];
		foreach ($checkpointsByStorage as $storageId => $objectIds)
		{
			$objectIds = array_values(array_unique(array_map('intval', $objectIds)));
			if ($objectIds !== [])
			{
				$grouped[(int)$storageId] = $objectIds;
			}
		}

		return Json::encode((object)$grouped);
	}

	/**
	 * @return array<int, int[]>
	 * @throws ArgumentException
	 */
	private function decodeCheckpointsByStorage(?string $checkpointsByStorage): array
	{
		if ($checkpointsByStorage === null || $checkpointsByStorage === '')
		{
			return [];
		}

		$grouped = [];
		foreach (Json::decode($checkpointsByStorage) as $storageId => $objectIds)
		{
			$grouped[(int)$storageId] = array_map('intval', $objectIds);
		}

		return $grouped;
	}
}
