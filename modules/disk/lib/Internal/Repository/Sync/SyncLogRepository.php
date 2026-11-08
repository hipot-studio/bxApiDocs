<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository\Sync;

use Bitrix\Disk\Internal\Model\SyncLogCodeTable;
use Bitrix\Disk\Internal\Model\SyncLogTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * The journal of changes and the access codes each of its records was published to.
 *
 * A record is published with its codes and is never rewritten afterwards: the set of codes is fixed at
 * the moment of publication, and an ageing set is cut off by the final visibility check when a card is
 * assembled - which is why the codes of a change of access are not corrected retrospectively either.
 *
 * The two shapes of a record are two operations here. A change of an object carries an operation and the
 * fields it touched; a change of access carries no operation at all and instead carries the scope of the
 * change and whether it reaches the descendants of that scope.
 */
final class SyncLogRepository
{
	/** How many identifiers one statement addressed to a set of records carries. */
	private const IDS_PER_QUERY = 500;

	/**
	 * @param string[] $accessCodes Codes the record is published to.
	 * @param string[]|null $changedFields Names of the fields the change touched.
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function addObjectChange(
		string $changeType,
		string $operation,
		string $resourceType,
		int $resourceId,
		array $accessCodes,
		?int $authorId = null,
		?array $changedFields = null,
		?string $tombstoneReason = null,
	): AddResult
	{
		return $this->add(
			[
				'CHANGE_TYPE' => $changeType,
				'OPERATION' => $operation,
				'RESOURCE_TYPE' => $resourceType,
				'RESOURCE_ID' => $resourceId,
				'AUTHOR_ID' => $authorId,
				'CHANGED_FIELDS' => $this->encodeChangedFields($changedFields),
				'TOMBSTONE_REASON' => $tombstoneReason,
			],
			$accessCodes,
		);
	}

	/**
	 * A change of access reports a boundary of visibility rather than a state of an object, so it has no
	 * operation. The resource is absent in one case only - when the set of codes of a user narrowed and
	 * the change cannot be tied to a resource at all.
	 *
	 * @param string[] $accessCodes Codes the record is published to.
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function addAccessChange(
		string $changeType,
		string $accessScope,
		bool $affectsDescendants,
		array $accessCodes,
		?string $resourceType = null,
		?int $resourceId = null,
		?int $scopeObjectId = null,
		?int $authorId = null,
	): AddResult
	{
		return $this->add(
			[
				'CHANGE_TYPE' => $changeType,
				'RESOURCE_TYPE' => $resourceType,
				'RESOURCE_ID' => $resourceId,
				'AUTHOR_ID' => $authorId,
				'ACCESS_SCOPE' => $accessScope,
				'SCOPE_OBJECT_ID' => $scopeObjectId,
				'AFFECTS_DESCENDANTS' => $affectsDescendants,
			],
			$accessCodes,
		);
	}

	/**
	 * Attaching the codes of a record is idempotent by the pair of the record and the code: a publication
	 * that failed halfway through its codes is retried by repeating the whole set.
	 *
	 * @param string[] $accessCodes
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function addCodes(int $logId, array $accessCodes): void
	{
		$codes = $this->normalizeCodes($accessCodes);
		if (!$codes)
		{
			return;
		}

		// The codes of a record go in one statement. Insert-ignore fires no events either way, and asking
		// the orm to keep them turns the write back into one query per code.
		SyncLogCodeTable::addInsertIgnoreMulti(
			array_map(
				static fn (string $code): array => [
					'LOG_ID' => $logId,
					'ACCESS_CODE' => $code,
				],
				$codes,
			),
			ignoreEvents: true,
		);
	}

	/**
	 * The oldest records past the retention, one portion of them, read through IX_DISK_SL_2.
	 *
	 * The identifiers are taken first and the deletion is addressed to them, because a portion cannot be
	 * expressed as a limit on the deletion itself in both supported databases. Taking them is also the
	 * only step of the cleanup short enough to hold a named lock around.
	 *
	 * @return int[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findIdsOlderThan(DateTime $createdBefore, int $limit): array
	{
		$rows = SyncLogTable::query()
			->setSelect(['ID'])
			->where('CREATED_AT', '<', $createdBefore)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * The first record written at or after the given moment, by identifier.
	 *
	 * It is asked for as the floor a position must stay under: everything below the answer was written
	 * before the moment, whatever the order of the identifiers and the times happens to be. Taking the
	 * greatest identifier below the moment instead would read the whole of the retention and would
	 * step over a record that got a low identifier and a later time.
	 *
	 * Read through IX_DISK_SL_2, so what it walks is the stretch of journal above the moment and not
	 * the depth of the journal below it.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findFirstIdCreatedSince(DateTime $moment): ?int
	{
		$row = SyncLogTable::query()
			->registerRuntimeField(
				new ExpressionField('FIRST_ID', 'MIN(%s)', 'ID', ['data_type' => 'integer']),
			)
			->setSelect(['FIRST_ID'])
			->where('CREATED_AT', '>=', $moment)
			->fetch()
		;

		if (!is_array($row) || $row['FIRST_ID'] === null)
		{
			return null;
		}

		return (int)$row['FIRST_ID'];
	}

	/**
	 * Which of the given records already carry codes of their own.
	 *
	 * A record is written before the codes addressing it, so one carrying none is either in the middle
	 * of its publication or was left without an audience for good. Neither can be handed to a reader,
	 * and telling the two apart by age is the business of the safe window.
	 *
	 * Read through IX_DISK_SLC_1 by its leading column, over the identifiers of the young tail alone.
	 *
	 * @param int[] $logIds
	 * @return int[] identifiers of those among them that carry at least one code
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findAddressedAmong(array $logIds): array
	{
		$ids = array_values(array_unique(array_map('intval', $logIds)));
		if ($ids === [])
		{
			return [];
		}

		$addressed = [];
		foreach (array_chunk($ids, self::IDS_PER_QUERY) as $chunk)
		{
			$rows = SyncLogCodeTable::query()
				->setSelect(['LOG_ID'])
				->whereIn('LOG_ID', $chunk)
				->setGroup(['LOG_ID'])
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$addressed[] = (int)$row['LOG_ID'];
			}
		}

		return $addressed;
	}

	/**
	 * The codes of the given records - the dependent rows, which go before the records themselves.
	 *
	 * The order is the whole of what makes an interrupted cleanup replayable: a run that stopped between
	 * the two steps leaves records whose codes are already gone, and the next run finds those records by
	 * the same age and finishes the pair. The opposite order would leave codes pointing at records that
	 * no longer exist, and nothing would ever look for them again.
	 *
	 * @param int[] $logIds
	 * @return int Number of code rows removed.
	 * @throws SqlQueryException
	 */
	public function deleteCodesOf(array $logIds): int
	{
		return $this->deleteByIn(SyncLogCodeTable::getTableName(), 'LOG_ID', $logIds);
	}

	/**
	 * @param int[] $logIds
	 * @return int Number of records removed.
	 * @throws SqlQueryException
	 */
	public function deleteRecords(array $logIds): int
	{
		return $this->deleteByIn(SyncLogTable::getTableName(), 'ID', $logIds);
	}

	/**
	 * When the journal last said anything about each of the given objects.
	 *
	 * The question behind it is always the same one: did the journal hear about a change the portal has
	 * a trace of somewhere else? It is answered for a whole portion of objects at a time and never for
	 * one of them, because the reconciliation walks the objects of a portal and a statement per object
	 * would turn that walk into a statement per row of it.
	 *
	 * The kinds of resource are passed in rather than assumed: they are the leading column of
	 * IX_DISK_SL_1, so a call that left them out would read the whole journal.
	 *
	 * @param int[] $resourceIds
	 * @param string[] $resourceTypes
	 * @param string|null $operation narrows the answer to one operation - a tombstone, say - instead of
	 *        any record about the object
	 * @return array<int, DateTime> resource identifier => the moment of its newest such record
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findLatestObjectRecordTimes(
		array $resourceIds,
		array $resourceTypes,
		DateTime $since,
		?string $operation = null,
	): array
	{
		return $this->latestTimesByResource(
			$resourceIds,
			$resourceTypes,
			$since,
			static function (Query $query) use ($operation): void {
				if ($operation === null)
				{
					// Object changes carry an operation and changes of access never do, so the column
					// is what separates the two shapes sharing the table.
					$query->whereNotNull('OPERATION');
				}
				else
				{
					$query->where('OPERATION', $operation);
				}
			},
		);
	}

	/**
	 * When a change of access of the given scope was last published over each of the given objects.
	 *
	 * A change of access stores the object of its scope in RESOURCE_ID as well as in SCOPE_OBJECT_ID,
	 * and only the first of the two is indexed - so that is the column asked here.
	 *
	 * @param int[] $scopeObjectIds
	 * @param string[] $resourceTypes
	 * @return array<int, DateTime> object of the scope => the moment of its newest such record
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findLatestAccessRecordTimes(
		array $scopeObjectIds,
		array $resourceTypes,
		string $accessScope,
		DateTime $since,
	): array
	{
		return $this->latestTimesByResource(
			$scopeObjectIds,
			$resourceTypes,
			$since,
			static function (Query $query) use ($accessScope): void {
				$query->where('ACCESS_SCOPE', $accessScope);
			},
		);
	}

	/**
	 * @param int[] $resourceIds
	 * @param string[] $resourceTypes
	 * @param callable(Query): void $narrow
	 * @return array<int, DateTime>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function latestTimesByResource(
		array $resourceIds,
		array $resourceTypes,
		DateTime $since,
		callable $narrow,
	): array
	{
		$ids = array_values(array_unique(array_map('intval', $resourceIds)));
		if ($ids === [] || $resourceTypes === [])
		{
			return [];
		}

		$latest = [];
		foreach (array_chunk($ids, self::IDS_PER_QUERY) as $chunk)
		{
			$query = SyncLogTable::query()
				->setSelect(['RESOURCE_ID'])
				->registerRuntimeField(
					new ExpressionField('LAST_AT', 'MAX(%s)', 'CREATED_AT', ['data_type' => 'datetime']),
				)
				->addSelect('LAST_AT')
				->whereIn('RESOURCE_TYPE', $resourceTypes)
				->whereIn('RESOURCE_ID', $chunk)
				->where('CREATED_AT', '>=', $since)
			;
			$narrow($query);

			foreach ($query->fetchAll() as $row)
			{
				if ($row['LAST_AT'] instanceof DateTime)
				{
					$latest[(int)$row['RESOURCE_ID']] = $row['LAST_AT'];
				}
			}
		}

		return $latest;
	}

	/**
	 * @param int[] $ids
	 * @throws SqlQueryException
	 */
	private function deleteByIn(string $tableName, string $column, array $ids): int
	{
		if ($ids === [])
		{
			return 0;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression('DELETE FROM ?# WHERE ?# IN (?@)', $tableName, $column, $ids))->compile()
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * The record is written first and its codes after it: a record nobody can reach yet is a record no
	 * reader reports, whereas codes pointing at a record that was never written would be.
	 *
	 * @param string[] $accessCodes
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function add(array $fields, array $accessCodes): AddResult
	{
		$result = SyncLogTable::add($fields);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->addCodes((int)$result->getId(), $accessCodes);

		return $result;
	}

	/**
	 * @param string[]|null $changedFields
	 * @throws ArgumentException
	 */
	private function encodeChangedFields(?array $changedFields): ?string
	{
		if ($changedFields === null)
		{
			return null;
		}

		return Json::encode(array_values(array_unique(array_map('strval', $changedFields))));
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function normalizeCodes(array $accessCodes): array
	{
		$codes = array_filter(
			array_unique(array_map('strval', $accessCodes)),
			static fn (string $code): bool => $code !== '',
		);

		return array_values($codes);
	}
}
