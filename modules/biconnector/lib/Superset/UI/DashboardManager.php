<?php

namespace Bitrix\BIConnector\Superset\UI;

use Bitrix\BIConnector\Integration\Pull\PullManager;

final class DashboardManager
{
	private const DASHBOARD_NOTIFY_TAG = 'superset_dashboard';

	/**
	 * Notify client-side that batch of dashboard changed status
	 *
	 * @param array $dashboardList in format [['id' => *idOfDashboard*(int), 'status' => *DashboardStatus*(string)], ...]
	 * @return void
	 */
	public static function notifyBatchDashboardStatus(array $dashboardList): void
	{
		PullManager::getNotifyer()->notifyByTag(
			self::DASHBOARD_NOTIFY_TAG,
			'onDashboardStatusUpdated',
			[
				'dashboardList' => $dashboardList,
			]
		);
	}

	/**
	 * Notify client-side that particular dashboard changed his status
	 *
	 * @param int $dashboardId
	 * @param string $status
	 * @return void
	 */
	public static function notifyDashboardStatus(int $dashboardId, string $status): void
	{
		self::notifyBatchDashboardStatus([
			[
				'id' => $dashboardId,
				'status' => $status,
			]
		]);
	}

	public static function notifySupersetStatus(string $status): void
	{
		PullManager::getNotifyer()->notifyByTag(
			self::DASHBOARD_NOTIFY_TAG,
			'onSupersetStatusUpdated',
			[
				'status' => $status,
			]
		);
	}

	/**
	 * Notify client side when all system dashboards had installed to reload grid.
	 *
	 * @return void
	 */
	public static function notifyInitialDashboardsInstalled(): void
	{
		PullManager::getNotifyer()->notifyByTag(
			self::DASHBOARD_NOTIFY_TAG,
			'onInitialDashboardsInstalled',
		);
	}
}
