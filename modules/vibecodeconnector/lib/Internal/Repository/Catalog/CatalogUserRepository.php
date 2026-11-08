<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\CatalogUserTable;

final class CatalogUserRepository
{
	public function markLeft(int $catalogItemId, int $userId): void
	{
		if ($catalogItemId <= 0 || $userId <= 0)
		{
			return;
		}

		$existing = $this->findId($catalogItemId, $userId);
		if ($existing !== null)
		{
			CatalogUserTable::update($existing, ['LEFT_AT' => new DateTime(), 'ARRIVAL_PENDING_AT' => null]);

			return;
		}

		CatalogUserTable::add([
			'USER_ID' => $userId,
			'CATALOG_ITEM_ID' => $catalogItemId,
			'LEFT_AT' => new DateTime(),
		]);
	}

	public function forgetLeft(int $catalogItemId, int $userId): void
	{
		$existing = $this->findId($catalogItemId, $userId);
		if ($existing === null)
		{
			return;
		}

		CatalogUserTable::update($existing, ['LEFT_AT' => null]);
	}

	public function isMarkedLeft(int $catalogItemId, int $userId): bool
	{
		if ($catalogItemId <= 0 || $userId <= 0)
		{
			return false;
		}

		$row = CatalogUserTable::query()
			->setSelect(['ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->where('USER_ID', $userId)
			->whereNotNull('LEFT_AT')
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterLeft(int $catalogItemId, array $userIds): array
	{
		$userIds = $this->normalizeIds($userIds);
		if ($catalogItemId <= 0 || $userIds === [])
		{
			return [];
		}

		$rows = CatalogUserTable::query()
			->setSelect(['USER_ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->whereIn('USER_ID', $userIds)
			->whereNotNull('LEFT_AT')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @return int[]
	 */
	public function getLeftUserIdsAfter(int $catalogItemId, int $afterUserId, int $limit): array
	{
		if ($catalogItemId <= 0 || $limit <= 0)
		{
			return [];
		}

		$rows = CatalogUserTable::query()
			->setSelect(['USER_ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->whereNotNull('LEFT_AT')
			->where('USER_ID', '>', max(0, $afterUserId))
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $userIds
	 * @throws \RuntimeException
	 */
	public function markArrivalPending(int $catalogItemId, array $userIds): void
	{
		$userIds = $this->normalizeIds($userIds);
		if ($catalogItemId <= 0 || $userIds === [])
		{
			return;
		}

		$pendingAt = new DateTime();
		$rows = [];
		foreach ($userIds as $userId)
		{
			$rows[] = [
				'USER_ID' => $userId,
				'CATALOG_ITEM_ID' => $catalogItemId,
				'ARRIVAL_PENDING_AT' => $pendingAt,
			];
		}

		$result = CatalogUserTable::addMergeMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
	}

	/**
	 * @param int[] $userIds
	 */
	public function clearArrivalPending(int $catalogItemId, array $userIds): void
	{
		$userIds = $this->normalizeIds($userIds);
		if ($catalogItemId <= 0 || $userIds === [])
		{
			return;
		}

		$this->clearArrivalPendingWhere(
			'CATALOG_ITEM_ID',
			$catalogItemId,
			'USER_ID',
			$userIds,
		);
	}

	/**
	 * @param int[] $catalogItemIds
	 */
	public function clearArrivalPendingForUser(int $userId, array $catalogItemIds): void
	{
		$catalogItemIds = $this->normalizeIds($catalogItemIds);
		if ($userId <= 0 || $catalogItemIds === [])
		{
			return;
		}

		$this->clearArrivalPendingWhere(
			'USER_ID',
			$userId,
			'CATALOG_ITEM_ID',
			$catalogItemIds,
		);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterArrivalPending(int $catalogItemId, array $userIds): array
	{
		$userIds = $this->normalizeIds($userIds);
		if ($catalogItemId <= 0 || $userIds === [])
		{
			return [];
		}

		$rows = CatalogUserTable::query()
			->setSelect(['USER_ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->whereIn('USER_ID', $userIds)
			->whereNotNull('ARRIVAL_PENDING_AT')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $catalogItemIds
	 * @return int[]
	 */
	public function filterArrivalPendingItemIds(int $userId, array $catalogItemIds): array
	{
		$catalogItemIds = $this->normalizeIds($catalogItemIds);
		if ($userId <= 0 || $catalogItemIds === [])
		{
			return [];
		}

		$rows = CatalogUserTable::query()
			->setSelect(['CATALOG_ITEM_ID'])
			->where('USER_ID', $userId)
			->whereIn('CATALOG_ITEM_ID', $catalogItemIds)
			->whereNotNull('ARRIVAL_PENDING_AT')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['CATALOG_ITEM_ID'], $rows);
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		if ($catalogItemId <= 0)
		{
			return;
		}

		CatalogUserTable::deleteByFilter(['=CATALOG_ITEM_ID' => $catalogItemId]);
	}

	public function deleteAllForUser(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		CatalogUserTable::deleteByFilter(['=USER_ID' => $userId]);
	}

	/**
	 * @param int[] $inValues
	 */
	private function clearArrivalPendingWhere(
		string $keyColumn,
		int $keyValue,
		string $inColumn,
		array $inValues,
	): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$pendingColumn = $helper->quote('ARRIVAL_PENDING_AT');
		$connection->queryExecute(
			'UPDATE ' . $helper->quote(CatalogUserTable::getTableName())
			. ' SET ' . $pendingColumn . ' = NULL'
			. ' WHERE ' . $helper->quote($keyColumn) . ' = ' . $keyValue
			. ' AND ' . $helper->quote($inColumn) . ' IN (' . implode(', ', $inValues) . ')'
			. ' AND ' . $pendingColumn . ' IS NOT NULL',
		);
	}

	private function findId(int $catalogItemId, int $userId): ?int
	{
		$row = CatalogUserTable::query()
			->setSelect(['ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function normalizeIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
