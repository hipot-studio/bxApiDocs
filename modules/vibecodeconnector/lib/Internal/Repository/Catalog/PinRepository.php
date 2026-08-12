<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\PinTable;

final class PinRepository
{
	public function pin(int $userId, int $catalogItemId): void
	{
		$existing = $this->findId($userId, $catalogItemId);
		if ($existing !== null)
		{
			PinTable::update($existing, ['PINNED_AT' => new DateTime()]);

			return;
		}

		PinTable::add([
			'USER_ID' => $userId,
			'CATALOG_ITEM_ID' => $catalogItemId,
			'PINNED_AT' => new DateTime(),
		]);
	}

	public function unpin(int $userId, int $catalogItemId): void
	{
		$existing = $this->findId($userId, $catalogItemId);
		if ($existing !== null)
		{
			PinTable::delete($existing);
		}
	}

	public function isPinned(int $userId, int $catalogItemId): bool
	{
		return $this->findId($userId, $catalogItemId) !== null;
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		$rows = PinTable::query()
			->setSelect(['ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->fetchAll();

		foreach ($rows as $row)
		{
			PinTable::delete((int)$row['ID']);
		}
	}

	private function findId(int $userId, int $catalogItemId): ?int
	{
		$row = PinTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
