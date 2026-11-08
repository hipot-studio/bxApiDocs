<?php

namespace Bitrix\BIConnector\Internal\Repository;

use Bitrix\BIConnector\Internal\Model\SupersetDashboardShareBanTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

class SupersetDashboardShareBanRepository
{
	public function isBanned(int $dashboardId): bool
	{
		return (bool)SupersetDashboardShareBanTable::getRow([
			'select' => ['ID'],
			'filter' => ['=DASHBOARD_ID' => $dashboardId],
		]);
	}

	/**
	 * Locking read: waits for a ban being set concurrently to commit or roll back.
	 */
	public function isBannedForUpdate(int $dashboardId): bool
	{
		$row = Application::getConnection()->query(
			(new SqlExpression(
				'SELECT ?# FROM ?# WHERE ?# = ?i FOR UPDATE',
				'ID',
				SupersetDashboardShareBanTable::getTableName(),
				'DASHBOARD_ID',
				$dashboardId,
			))->compile()
		)->fetch();

		return (bool)$row;
	}

	/**
	 * @param int[] $dashboardIds
	 * @return int[]
	 */
	public function getBannedDashboardIds(array $dashboardIds): array
	{
		if (empty($dashboardIds))
		{
			return [];
		}

		$rows = SupersetDashboardShareBanTable::getList([
			'select' => ['DASHBOARD_ID'],
			'filter' => ['@DASHBOARD_ID' => $dashboardIds],
		])->fetchAll();

		return array_map('intval', array_column($rows, 'DASHBOARD_ID'));
	}

	/**
	 * @return bool False when the dashboard is already banned.
	 */
	public function add(int $dashboardId, int $userId): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = SupersetDashboardShareBanTable::getTableName();

		[$columns, $values] = $helper->prepareInsert($tableName, [
			'DASHBOARD_ID' => $dashboardId,
			'CREATED_BY_ID' => $userId,
			'DATE_CREATE' => new DateTime(),
		]);

		$connection->queryExecute(
			$helper->getInsertIgnore($helper->quote($tableName), " ({$columns})", " VALUES ({$values})")
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * @return bool False when the dashboard was not banned.
	 */
	public function remove(int $dashboardId): bool
	{
		$connection = Application::getConnection();
		$connection->queryExecute(
			(new SqlExpression(
				'DELETE FROM ?# WHERE ?# = ?i',
				SupersetDashboardShareBanTable::getTableName(),
				'DASHBOARD_ID',
				$dashboardId,
			))->compile()
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	public function deleteByDashboardId(int $dashboardId): void
	{
		SupersetDashboardShareBanTable::deleteByFilter(['=DASHBOARD_ID' => $dashboardId]);
	}
}
