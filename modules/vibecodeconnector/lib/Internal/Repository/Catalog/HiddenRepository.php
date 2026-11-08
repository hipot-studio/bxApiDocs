<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\HiddenTable;

final class HiddenRepository
{
	private const USER_CHUNK_SIZE = 2000;

	public function upsert(int $userId, int $catalogItemId): void
	{
		$existing = $this->findId($userId, $catalogItemId);
		if ($existing !== null)
		{
			HiddenTable::update($existing, ['HIDDEN_AT' => new DateTime()]);

			return;
		}

		HiddenTable::add([
			'USER_ID' => $userId,
			'CATALOG_ITEM_ID' => $catalogItemId,
			'HIDDEN_AT' => new DateTime(),
		]);
	}

	public function delete(int $userId, int $catalogItemId): void
	{
		$existing = $this->findId($userId, $catalogItemId);
		if ($existing !== null)
		{
			HiddenTable::delete($existing);
		}
	}

	public function deleteForUsers(int $catalogItemId, array $userIds): void
	{
		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($catalogItemId <= 0 || $userIds === [])
		{
			return;
		}

		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			HiddenTable::deleteByFilter([
				'=CATALOG_ITEM_ID' => $catalogItemId,
				'@USER_ID' => $chunk,
			]);
		}
	}

	public function exists(int $userId, int $catalogItemId): bool
	{
		return $this->findId($userId, $catalogItemId) !== null;
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		$connection = Application::getConnection();
		$connection->query(
			'DELETE FROM ' . HiddenTable::getTableName() . ' WHERE CATALOG_ITEM_ID = ' . $catalogItemId,
		);
	}

	/**
	 * User ids that have hidden the given catalog item.
	 *
	 * @return int[]
	 */
	public function userIdsForItem(int $catalogItemId): array
	{
		$rows = HiddenTable::query()
			->setSelect(['USER_ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->fetchAll();

		return array_map(static fn (array $row) => (int)$row['USER_ID'], $rows);
	}

	private function findId(int $userId, int $catalogItemId): ?int
	{
		$row = HiddenTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
