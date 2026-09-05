<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main;
use Bitrix\Main\Result;
use Bitrix\Rest;

/**
 * Binds bizproceff dashboards to admin and uninstalls their applications (without deleting dashboards).
 * System dashboards without an external object are placeholders with nothing behind them - they are deleted.
 *
 * Owners in Superset are intentionally left alone: setOwner() skips entities owned by the service user only,
 * and a Market dashboard with its dataset and charts is exactly in that state.
 */
final class Version13 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();
		$supersetStatus = SupersetInitializer::getSupersetStatus();

		if (
			$supersetStatus === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS
			|| $supersetStatus === SupersetInitializer::SUPERSET_STATUS_DELETED
			|| SupersetInitializer::isSupersetPendingDelete()
		)
		{
			return $result;
		}

		$admin = (new SupersetUserRepository())->getAdmin();
		if ($admin === null)
		{
			$result->addError(new Main\Error('No users in admins group were found.'));

			return $result;
		}

		$dashboards = SupersetDashboardTable::getList([
			'select' => ['*', 'APP'],
			'filter' => [
				'=APP_ID' => [
					'bitrix.bic_bizproceff',
					'alaio.bic_bizproceff',
				],
			],
		])
			->fetchCollection()
		;

		foreach ($dashboards as $dashboard)
		{
			// EXTERNAL_ID is not nullable in ORM, so NULL from db is cast to 0
			if (
				$dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
				&& !$dashboard->getExternalId()
			)
			{
				$deleteResult = SupersetDashboardTable::delete($dashboard->getId());
				if (!$deleteResult->isSuccess())
				{
					$result->addErrors($deleteResult->getErrors());

					return $result;
				}

				continue;
			}

			$dashboardResult = $this->processInstalledDashboard($dashboard, $admin->id);
			if (!$dashboardResult->isSuccess())
			{
				$result->addErrors($dashboardResult->getErrors());

				return $result;
			}
		}

		return $result;
	}

	private function processInstalledDashboard(SupersetDashboard $dashboard, int $adminId): Result
	{
		$result = new Result();

		if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
		{
			$dashboard
				->setCreatedById($adminId)
				->setType(SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
			;

			$appId = $dashboard->getApp()?->getId();
			if ($appId)
			{
				Rest\AppTable::uninstall($appId);
				Rest\AppTable::update(
					$appId,
					['ACTIVE' => 'N', 'INSTALLED' => 'N'],
				);
			}
		}

		if ($dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$dashboard->setAppId(null);
		}

		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			$result->addErrors($saveResult->getErrors());
		}

		return $result;
	}
}
