<?php

namespace Bitrix\BIConnector\Superset\Import;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Update\Stepper;
use CUser;

final class DashboardReimportService extends Stepper
{
	private const BATCH_SIZE = 2;
	protected static $moduleId = 'biconnector';

	public static function runForAllInstalled(): void
	{
		$appCodes = self::collectInstalledAppCodes();
		if (empty($appCodes))
		{
			return;
		}

		self::bind(1);
	}

	private static function collectInstalledAppCodes(): array
	{
		$allSystemApps = SystemDashboardManager::getSystemApps();
		$codes = array_column($allSystemApps, 'code');

		$rows = SupersetDashboardTable::getList([
			'select' => ['APP_ID'],
			'filter' => [
				'@APP_ID' => $codes,
				'!=LANG' => CultureFormatter::getLanguageCode(),
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
				'=SOURCE_ID' => null,
				'=STATUS' => SupersetDashboardTable::DASHBOARD_STATUS_READY,
			],
			'cache' => ['ttl' => 300],
		])
			->fetchAll()
		;

		return array_values(array_unique(array_column($rows, 'APP_ID')));
	}

	public static function getTitle(): string
	{
		return Loc::getMessage('BICONNECTOR_DASHBOARD_REIMPORT_TITLE');
	}

	public function execute(array &$option): bool
	{
		global $USER;

		if (!isset($USER))
		{
			$USER = new CUser();
		}

		if (empty($option))
		{
			$appCodes = self::collectInstalledAppCodes();
			if (empty($appCodes))
			{
				return self::FINISH_EXECUTION;
			}

			$option['steps'] = 0;
			$option['count'] = count($appCodes);
			$option['appCodes'] = $appCodes;
		}

		$currentSteps = 0;

		while (!empty($option['appCodes']) && $currentSteps < self::BATCH_SIZE)
		{
			$appCode = array_shift($option['appCodes']);
			MarketDashboardManager::getInstance()->installApplication($appCode);

			$currentSteps++;
		}

		$option['steps'] += $currentSteps;

		return $option["steps"] >= $option["count"] ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}
}
