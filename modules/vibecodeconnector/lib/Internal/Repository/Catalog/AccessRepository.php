<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AudienceCodeSet;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\AccessTable;

final class AccessRepository
{
	public function grant(int $catalogItemId, string $accessCode): void
	{
		if ($this->findId($catalogItemId, $accessCode) !== null)
		{
			return;
		}

		AccessTable::add([
			'CATALOG_ITEM_ID' => $catalogItemId,
			'ACCESS_CODE' => $accessCode,
			'GRANTED_AT' => new DateTime(),
		]);
	}

	public function revoke(int $catalogItemId, string $accessCode): void
	{
		$existing = $this->findId($catalogItemId, $accessCode);
		if ($existing !== null)
		{
			AccessTable::delete($existing);
		}
	}

	public function exists(int $catalogItemId, string $accessCode): bool
	{
		return $this->findId($catalogItemId, $accessCode) !== null;
	}

	/**
	 * @return string[]
	 */
	public function getCodesForItem(int $catalogItemId): array
	{
		$rows = AccessTable::query()
			->setSelect(['ACCESS_CODE'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->fetchAll();

		return array_map(static fn (array $row) => (string)$row['ACCESS_CODE'], $rows);
	}

	/**
	 * @return string[]
	 */
	public function getDistinctDepartmentCodes(): array
	{
		$rows = AccessTable::query()
			->setSelect(['ACCESS_CODE'])
			->setDistinct()
			->whereLike('ACCESS_CODE', 'D%')
			->fetchAll()
		;

		$codes = array_values(array_unique(array_filter(
			array_map(static fn (array $row): string => (string)$row['ACCESS_CODE'], $rows),
			static fn (string $code): bool => str_starts_with($code, 'D') && AudienceCodeSet::isSupported($code),
		)));
		sort($codes, SORT_STRING);

		return $codes;
	}

	/**
	 * @param int[] $catalogItemIds
	 *
	 * @return array<int, string[]>
	 */
	public function getCodesByItemIds(array $catalogItemIds): array
	{
		if ($catalogItemIds === [])
		{
			return [];
		}

		$rows = AccessTable::query()
			->setSelect(['CATALOG_ITEM_ID', 'ACCESS_CODE'])
			->whereIn('CATALOG_ITEM_ID', $catalogItemIds)
			->fetchAll()
		;

		$codesByItem = [];
		foreach ($rows as $row)
		{
			$codesByItem[(int)$row['CATALOG_ITEM_ID']][] = (string)$row['ACCESS_CODE'];
		}

		return $codesByItem;
	}

	/**
	 * @return string[]
	 */
	public function getCodesForItemThroughId(int $catalogItemId, int $maxAccessId): array
	{
		if ($catalogItemId <= 0 || $maxAccessId <= 0)
		{
			return [];
		}

		$rows = AccessTable::query()
			->setSelect(['ACCESS_CODE'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->where('ID', '<=', $maxAccessId)
			->fetchAll()
		;

		return array_map(static fn (array $row): string => (string)$row['ACCESS_CODE'], $rows);
	}

	/**
	 * @param array<int, int> $maxAccessIdByItem
	 * @param string[] $userCodes
	 * @return int[]
	 */
	public function filterItemIdsGrantedThrough(array $maxAccessIdByItem, array $userCodes): array
	{
		$maxAccessIdByItem = array_filter(
			array_map('intval', $maxAccessIdByItem),
			static fn (int $maxAccessId, int $catalogItemId): bool => $catalogItemId > 0 && $maxAccessId > 0,
			ARRAY_FILTER_USE_BOTH,
		);
		$userCodes = array_values(array_unique(array_filter(
			$userCodes,
			static fn (mixed $code): bool => is_string($code) && $code !== '',
		)));
		if ($maxAccessIdByItem === [] || $userCodes === [])
		{
			return [];
		}

		$rows = AccessTable::query()
			->setSelect(['ID', 'CATALOG_ITEM_ID'])
			->whereIn('CATALOG_ITEM_ID', array_keys($maxAccessIdByItem))
			->whereIn('ACCESS_CODE', $userCodes)
			->fetchAll()
		;
		$granted = [];
		foreach ($rows as $row)
		{
			$itemId = (int)$row['CATALOG_ITEM_ID'];
			if ((int)$row['ID'] <= $maxAccessIdByItem[$itemId])
			{
				$granted[$itemId] = true;
			}
		}

		return array_map('intval', array_keys($granted));
	}

	/**
	 * @param string[] $accessCodes
	 */
	public function setAccess(int $catalogItemId, array $accessCodes): void
	{
		$existing = $this->getCodesForItem($catalogItemId);

		$toAdd = array_diff($accessCodes, $existing);
		$toRemove = array_diff($existing, $accessCodes);

		foreach ($toRemove as $code)
		{
			$this->revoke($catalogItemId, $code);
		}

		foreach ($toAdd as $code)
		{
			$this->grant($catalogItemId, $code);
		}
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		$rows = AccessTable::query()
			->setSelect(['ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->fetchAll();

		foreach ($rows as $row)
		{
			AccessTable::delete((int)$row['ID']);
		}
	}

	private function findId(int $catalogItemId, string $accessCode): ?int
	{
		$row = AccessTable::query()
			->setSelect(['ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->where('ACCESS_CODE', $accessCode)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
