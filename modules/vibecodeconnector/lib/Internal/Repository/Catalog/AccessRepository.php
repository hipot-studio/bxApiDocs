<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Type\DateTime;
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
