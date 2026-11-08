<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Integration\Superset\Integrator\ServiceLocation;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\Demo\DemoService;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loader::includeModule('biconnector');

final class ApacheSupersetDashboardDemoComponent extends CBitrixComponent
{
	public function onPrepareComponentParams($arParams)
	{
		$arParams['REPORT_CODE'] = trim((string)($arParams['REPORT_CODE'] ?? ''));

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		$reportCode = $this->arParams['REPORT_CODE'];
		$isAvailable = (new DemoService())->isAvailable();
		$dashboard = $isAvailable ? $this->findSystemDashboard($reportCode) : null;

		$this->arResult = [
			'REPORT_CODE' => $reportCode,
			'IS_AVAILABLE' => $isAvailable,
			'TITLE' => $isAvailable ? $this->getDemoTitle($dashboard) : $this->getDefaultTitle(),
			'DASHBOARD_ID' => (int)($dashboard['ID'] ?? 0),
			'SERVICE_LOCATION' => ServiceLocation::getCurrentDatacenterLocationRegion(),
		];

		$this->includeComponentTemplate();
	}

	private function getDefaultTitle(): string
	{
		return (string)Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DEMO_DEFAULT_TITLE');
	}

	private function findSystemDashboard(string $reportCode): ?array
	{
		if ($reportCode === '')
		{
			return null;
		}

		$row = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'=APP_ID' => $reportCode,
				'=TYPE' => SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	private function getDemoTitle(?array $dashboard): string
	{
		$title = trim((string)($dashboard['TITLE'] ?? ''));

		return $title === '' ? $this->getDefaultTitle() : $title;
	}
}
