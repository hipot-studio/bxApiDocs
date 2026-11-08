<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserAccessTable;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\ViewedTable;

final class ViewedRepository
{
	/**
	 * Marks the whole set as viewed in a single insert. Already existing rows keep their
	 * original VIEWED_AT: the conflict on the unique user + item pair is ignored instead
	 * of merged, so a repeated view neither shifts the timestamp nor duplicates the row.
	 *
	 * @param int[] $catalogItemIds
	 */
	public function markViewed(int $userId, array $catalogItemIds): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$itemIds = array_unique(array_filter(
			array_map('intval', $catalogItemIds),
			static fn(int $itemId): bool => $itemId > 0,
		));
		if ($itemIds === [])
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$viewedAt = $helper->convertToDbDateTime(new DateTime());
		$rows = [];
		foreach ($itemIds as $itemId)
		{
			$rows[] = '(' . $userId . ', ' . $itemId . ', ' . $viewedAt . ')';
		}

		$columns = implode(', ', [
			$helper->quote('USER_ID'),
			$helper->quote('CATALOG_ITEM_ID'),
			$helper->quote('VIEWED_AT'),
		]);

		$connection->queryExecute(
			$helper->getInsertIgnore(
				$helper->quote(ViewedTable::getTableName()),
				' (' . $columns . ') ',
				'VALUES ' . implode(', ', $rows),
			),
		);
	}

	public function delete(int $userId, int $catalogItemId): void
	{
		ViewedTable::deleteByFilter([
			'=USER_ID' => $userId,
			'=CATALOG_ITEM_ID' => $catalogItemId,
		]);
	}

	public function deleteAllForCatalogItem(int $catalogItemId): void
	{
		ViewedTable::deleteByFilter(['=CATALOG_ITEM_ID' => $catalogItemId]);
	}

	/**
	 * Drops the view marks of everyone who lost access to the item in a single statement:
	 * the projection holds a row per user the catalog was shown to, so a per-user access
	 * check would not scale. The owner keeps their mark, and so does anyone holding one of
	 * the item's remaining access codes.
	 *
	 * Code ownership is read straight from b_user_access, which CAccess fills lazily, so a
	 * user whose rows are not materialized yet counts as having lost access and loses the
	 * mark. Accepted: seeing an acl item materializes the codes anyway, and the worst case
	 * is the item showing up as new once more.
	 *
	 * @param string[] $itemAccessCodes remaining access codes of the item; empty means nobody but the owner
	 */
	public function deleteForUsersWithoutAccess(int $catalogItemId, int $ownerId, array $itemAccessCodes): void
	{
		if ($catalogItemId <= 0)
		{
			return;
		}

		if (AccessCodes::grantsEveryone($itemAccessCodes))
		{
			return;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(ViewedTable::getTableName());
		$userIdColumn = $helper->quote('USER_ID');

		$sql = 'DELETE FROM ' . $table
			. ' WHERE ' . $helper->quote('CATALOG_ITEM_ID') . ' = ' . $catalogItemId
			. ' AND ' . $userIdColumn . ' <> ' . $ownerId
		;

		$codes = array_values(array_unique(array_filter(
			$itemAccessCodes,
			static fn(string $code): bool => trim($code) !== '',
		)));
		if ($codes !== [])
		{
			$sql .= ' AND NOT EXISTS ('
				. $this->accessCodeHoldersQuery($table . '.' . $userIdColumn, $codes)
				. ')'
			;
		}

		$connection->queryExecute($sql);
	}

	/**
	 * @param string[] $codes
	 */
	private function accessCodeHoldersQuery(string $userIdExpression, array $codes): string
	{
		$helper = Application::getConnection()->getSqlHelper();
		$userAccess = $helper->quote(UserAccessTable::getTableName());

		$query = 'SELECT 1 FROM ' . $userAccess
			. ' WHERE ' . $userAccess . '.' . $helper->quote('USER_ID') . ' = ' . $userIdExpression
		;

		$quotedCodes = array_map(
			static fn(string $code): string => "'" . $helper->forSql($code) . "'",
			$codes,
		);

		return $query
			. ' AND ' . $userAccess . '.' . $helper->quote('ACCESS_CODE')
			. ' IN (' . implode(', ', $quotedCodes) . ')'
		;
	}

	/**
	 * User ids that have viewed the given catalog item.
	 *
	 * @return int[]
	 */
	public function userIdsForItem(int $catalogItemId): array
	{
		$rows = ViewedTable::query()
			->setSelect(['USER_ID'])
			->where('CATALOG_ITEM_ID', $catalogItemId)
			->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
	}
}
