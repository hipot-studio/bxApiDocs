<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardCollection;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Rest\AppTable;

/**
 * Support for the new western vendor `alaio`:
 *  1) re-binding of system dashboards
 *  2) removing rest apps from the old vendor
 *  3) installing a new rest apps with update dashboards at superset
 *
 * Also changed market code for some dashboards (both vendors):
 *  1) bic_deals_ru/bic_deals_en -> bic_deals
 *  2) bic_throughput_flow -> bic_throughput
 */
final class Version5 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();

		if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			return $result;
		}

		if (
			SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY
			&& SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS
		)
		{
			$result->addError(new Main\Error('Superset status is not READY or DOESNT_EXISTS'));

			return $result;
		}

		$appCodeList = array_keys(SystemDashboardManager::getSystemApps());

		$changesCodeResult = $this->changeDashboardsCodes($appCodeList);
		if (!$changesCodeResult->isSuccess())
		{
			$result->addErrors($changesCodeResult->getErrors());

			return $result;
		}

		$migrateResult = $this->migrateToAlaioVendor($appCodeList);
		if (!$migrateResult->isSuccess())
		{
			$result->addErrors($migrateResult->getErrors());

			return $result;
		}

		return $result;
	}

	private function changeDashboardsCodes(array $appCodeList): Result
	{
		$result = new Result();

		$mapToMigrate = [
			'bitrix.bic_deals_ru' => 'bitrix.bic_deals',
			'bitrix.bic_deals_en' => 'alaio.bic_deals',
			'bitrix.bic_throughput_flow' => 'bitrix.bic_throughput',
			'alaio.bic_throughput_flow' => 'alaio.bic_throughput',
		];

		$dashboardsToMigrate = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'TYPE'],
			'filter' => [
				'=APP_ID' => array_keys($mapToMigrate),
			],
		])
			->fetchCollection()
		;

		if ($dashboardsToMigrate === null)
		{
			return $result;
		}

		$migrateResult = $this->migrateDashboardsWithMap($dashboardsToMigrate, $mapToMigrate);
		if (!$migrateResult->isSuccess())
		{
			$result->addErrors($migrateResult->getErrors());
		}

		return $result;
	}

	private function migrateToAlaioVendor(array $appCodeList): Result
	{
		$result = new Result();

		$existingAppCodeList = [];
		foreach ($appCodeList as $appCode)
		{
			$existAppCode = SystemDashboardManager::mapExistingAppCode($appCode);
			if ($existAppCode === $appCode)
			{
				continue;
			}

			// `bitrix.code` => `alaio.code`
			$existingAppCodeList[$existAppCode] = $appCode;
		}

		if (empty($existingAppCodeList))
		{
			return $result;
		}

		$dashboardToMigrateList = SupersetDashboardTable::getList([
			'select' => ['ID', 'APP_ID', 'TYPE'],
			'filter' => [
				'LOGIC' => 'OR',
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
				'!=SOURCE_ID' => null,
			],
		])
			->fetchCollection()
		;

		$migrateResult = $this->migrateDashboardsWithMap($dashboardToMigrateList, $existingAppCodeList);
		if (!$migrateResult->isSuccess())
		{
			$result->addErrors($migrateResult->getErrors());

			return $result;
		}

		return $result;
	}

	/**
	 * @param SupersetDashboardCollection $dashboardToMigrateList
	 * @param array $mapCodesToMigrate [ 'old.code' => 'new.code' ]
	 *
	 * @return Result
	 * @throws Main\ArgumentOutOfRangeException
	 */
	private function migrateDashboardsWithMap(
		SupersetDashboardCollection $dashboardToMigrateList,
		array $mapCodesToMigrate
	): Result
	{
		$result = new Result();

		$appCodeListToDelete = [];
		$dashboardIdListToReinstall = [];
		foreach ($dashboardToMigrateList as $dashboardToMigrate)
		{
			if (!isset($mapCodesToMigrate[$dashboardToMigrate->getAppId()]))
			{
				continue;
			}

			$existAppCode = $dashboardToMigrate->getAppId();
			$newAppId = $mapCodesToMigrate[$existAppCode];
			if ($newAppId === $existAppCode)
			{
				continue;
			}

			$dashboardToMigrate->setAppId($newAppId);
			$dashboardToMigrate->setDateModify(new Main\Type\DateTime());

			$saveResult = $dashboardToMigrate->save();
			if (!$saveResult->isSuccess())
			{
				continue;
			}

			if ($dashboardToMigrate->getType() !== SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
			{
				continue;
			}

			$appCodeListToDelete[] = $existAppCode;
			$dashboardIdListToReinstall[] = $dashboardToMigrate->getId();
		}

		if (empty($appCodeListToDelete) || empty($dashboardIdListToReinstall))
		{
			return $result;
		}

		$marketManager = MarketDashboardManager::getInstance();
		\Bitrix\Rest\Marketplace\Application::setContextUserId(Manager::getAdminId());
		$needToRestoreDeleteProtection = false;
		if (Option::get('biconnector', 'allow_delete_system_dashboard', 'N') === 'N')
		{
			$needToRestoreDeleteProtection = true;
			Option::set('biconnector', 'allow_delete_system_dashboard', 'Y');
		}

		$foundInstalledApps = AppTable::getList([
			'select' => ['ID', 'CODE'],
			'filter' => [
				'=CODE' => $appCodeListToDelete,
				'ACTIVE' => 'Y',
				'INSTALLED' => 'Y',
			],
		])
			->fetchCollection()
		;
		foreach ($foundInstalledApps->getCodeList() as $appCode)
		{
			$marketManager->handleUninstallMarketApp($appCode);
		}
		if ($needToRestoreDeleteProtection)
		{
			Option::set('biconnector', 'allow_delete_system_dashboard', 'N');
		}

		if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS)
		{
			return $result;
		}

		foreach ($dashboardIdListToReinstall as $dashboardId)
		{
			$marketManager->reinstallDashboard($dashboardId);
		}

		return $result;
	}
}
