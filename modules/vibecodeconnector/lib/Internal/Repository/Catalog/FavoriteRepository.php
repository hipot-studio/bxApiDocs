<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\FavoriteTable;

final class FavoriteRepository
{
	public function upsert(int $userId, int $catalogItemId): void
	{
		$connection = Application::getConnection();
		$favoritedAt = new DateTime();
		[$mergeSql] = $connection->getSqlHelper()->prepareMerge(
			FavoriteTable::getTableName(),
			['USER_ID', 'CATALOG_ITEM_ID'],
			[
				'USER_ID' => $userId,
				'CATALOG_ITEM_ID' => $catalogItemId,
				'FAVORITED_AT' => $favoritedAt,
			],
			['FAVORITED_AT' => $favoritedAt],
		);

		if ($mergeSql !== '')
		{
			$connection->queryExecute($mergeSql);

			return;
		}

		// Fallback for drivers where prepareMerge returns no SQL
		$existing = $this->findId($userId, $catalogItemId);
		if ($existing !== null)
		{
			FavoriteTable::update($existing, ['FAVORITED_AT' => $favoritedAt]);

			return;
		}

		try
		{
			FavoriteTable::add([
				'USER_ID' => $userId,
				'CATALOG_ITEM_ID' => $catalogItemId,
				'FAVORITED_AT' => $favoritedAt,
			]);
		}
		catch (\Throwable $e)
		{
			$existing = $this->findId($userId, $catalogItemId);
			if ($existing === null)
			{
				throw $e;
			}
			FavoriteTable::update($existing, ['FAVORITED_AT' => $favoritedAt]);
		}
	}

	public function delete(int $userId, int $catalogItemId): void
	{
		$connection = Application::getConnection();
		$connection->query(
			'DELETE FROM ' . FavoriteTable::getTableName()
			. ' WHERE USER_ID = ' . $userId . ' AND CATALOG_ITEM_ID = ' . $catalogItemId,
		);
	}

	public function exists(int $userId, int $catalogItemId): bool
	{
		return $this->findId($userId, $catalogItemId) !== null;
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		$connection = Application::getConnection();
		$connection->query(
			'DELETE FROM ' . FavoriteTable::getTableName() . ' WHERE CATALOG_ITEM_ID = ' . $catalogItemId,
		);
	}

	private function findId(int $userId, int $catalogItemId): ?int
	{
		$row = FavoriteTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
