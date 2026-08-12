<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\LastOpenedTable;

final class LastOpenedRepository
{
	public function recordOpen(int $userId, int $catalogItemId): void
	{
		$now = new DateTime();
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = LastOpenedTable::getTableName();

		[$mergeSql] = $helper->prepareMerge(
			$tableName,
			['USER_ID', 'CATALOG_ITEM_ID'],
			['USER_ID' => $userId, 'CATALOG_ITEM_ID' => $catalogItemId, 'OPENED_AT' => $now],
			['OPENED_AT' => $now],
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
			LastOpenedTable::update($existing, ['OPENED_AT' => $now]);

			return;
		}

		try
		{
			LastOpenedTable::add([
				'USER_ID' => $userId,
				'CATALOG_ITEM_ID' => $catalogItemId,
				'OPENED_AT' => $now,
			]);
		}
		catch (\Throwable $e)
		{
			$existing = $this->findId($userId, $catalogItemId);
			if ($existing === null)
			{
				throw $e;
			}
			LastOpenedTable::update($existing, ['OPENED_AT' => $now]);
		}
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		LastOpenedTable::deleteByFilter(['=CATALOG_ITEM_ID' => $catalogItemId]);
	}

	private function findId(int $userId, int $catalogItemId): ?int
	{
		$row = LastOpenedTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->setLimit(1)
			->fetch();

		return $row !== false ? (int)$row['ID'] : null;
	}
}
