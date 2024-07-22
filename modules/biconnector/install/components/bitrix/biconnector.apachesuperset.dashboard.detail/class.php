<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Integrator\ServiceLocation;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketDashboardManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loader::includeModule("biconnector");

class ApacheSupersetDashboardDetailComponent extends CBitrixComponent
{
	private SupersetController $supersetController;

	private ?Dashboard $dashboard;

	public function onPrepareComponentParams($arParams)
	{
		$arParams['DASHBOARD_ID'] = (int)($arParams['DASHBOARD_ID'] ?? 0);
		$arParams['CODE'] = $arParams['CODE'] ?? '';

		return parent::onPrepareComponentParams($arParams);
	}

	private function getSupersetController(): SupersetController
	{
		if (!isset($this->supersetController))
		{
			$this->supersetController = new SupersetController(Integrator::getInstance());
		}

		return $this->supersetController;
	}

	private function prepareResult(): void
	{
		$this->arResult = [
			'FEATURE_AVAILABLE' => true,
			'DASHBOARD_UUID' => null,
			'GUEST_TOKEN' => null,
			'SUPERSET_DOMAIN' => '',
			'ERROR_MESSAGES' => [],
			'NATIVE_FILTERS' => '',
			'MARKET_COLLECTION_URL' => MarketDashboardManager::getMarketCollectionUrl(),
			'SUPERSET_SERVICE_LOCATION' => ServiceLocation::getCurrentDatacenterLocationRegion(),
		];
	}

	public function executeComponent()
	{
		$dashboardId = (int)$this->arParams['DASHBOARD_ID'];
		$dashboard = SupersetDashboardTable::getByPrimary($dashboardId)->fetch();
		if (!$dashboard)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
			$this->includeComponentTemplate();
			return;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $dashboardId,
			'TYPE' => $dashboard['TYPE'],
			'OWNER_ID' => $dashboard['OWNER_ID'],
		]);

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_VIEW, $accessItem))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_ACCESS_ERROR');
			$this->includeComponentTemplate();

			return;
		}

		$this->prepareResult();
		$this->initDashboard();

		if (SupersetInitializer::isSupersetLoad())
		{
			$dashboard['STATUS'] = SupersetDashboardTable::DASHBOARD_STATUS_LOAD;
			$this->showStartupTemplate($dashboard);

			return;
		}

		if (SupersetInitializer::isSupersetDoesntWork())
		{
			$this->showStartupTemplate($dashboard, false);

			return;
		}


		if (
			$dashboard['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_READY
			&& SupersetInitializer::isSupersetReady()
			&& !$this->dashboard->isSupersetDashboardDataLoaded()
		)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
			$this->includeComponentTemplate();

			return;
		}

		if (in_array($dashboard['STATUS'], [SupersetDashboardTable::DASHBOARD_STATUS_LOAD, SupersetDashboardTable::DASHBOARD_STATUS_FAILED]))
		{
			$this->showStartupTemplate($dashboard);

			return;
		}

		if ($this->dashboard === null)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_DETAIL_NOT_FOUND');
			$this->includeComponentTemplate();
			return;
		}

		$this->prepareAccessParams();

		$this->prepareEmbeddedCredentials();
		$this->prepareNativeFilters();

		$this->includeComponentTemplate();
	}

	private function showStartupTemplate(array $dashboard, bool $supersetAvailable = true): void
	{
		$this->arResult['DASHBOARD_TITLE'] = $dashboard['TITLE'];
		$this->arResult['DASHBOARD_ID'] = $dashboard['ID'];
		$this->arResult['DASHBOARD_STATUS'] =
			$dashboard['STATUS'] === SupersetDashboardTable::DASHBOARD_STATUS_READY
				? SupersetDashboardTable::DASHBOARD_STATUS_LOAD
				: $dashboard['STATUS']
		;

		$this->arResult['IS_SUPERSET_AVAILABLE'] = $supersetAvailable;

		$this->includeComponentTemplate('startup');
	}

	private function initDashboard(): bool
	{
		$superset = $this->getSupersetController();
		$this->dashboard = $superset->getDashboardRepository()->getById((int)$this->arParams['DASHBOARD_ID']);
		if (!$this->dashboard?->isSupersetDashboardDataLoaded())
		{
			return false;
		}

		return true;
	}

	private function prepareEmbeddedCredentials(): void
	{
		$this->arResult['DASHBOARD_TYPE'] = $this->dashboard->getType();
		$this->arResult['DASHBOARD_TITLE'] = $this->dashboard->getTitle();
		$this->arResult['DASHBOARD_UUID'] = $this->dashboard->getEmbeddedCredentials()->uuid;
		$this->arResult['DASHBOARD_ID'] = $this->dashboard->getId();
		$this->arResult['GUEST_TOKEN'] = $this->dashboard->getEmbeddedCredentials()->guestToken;
		$this->arResult['SUPERSET_DOMAIN'] = $this->dashboard->getEmbeddedCredentials()->supersetDomain;
		$this->arResult['DASHBOARD_EDIT_URL'] = $this->dashboard->getEditUrl();
		$this->arResult['DASHBOARD_APP_ID'] = $this->dashboard->getAppId();
	}

	private function prepareNativeFilters(): void
	{
		$this->arResult['NATIVE_FILTERS'] = $this->dashboard->getNativeFilter();
	}

	private function prepareAccessParams(): void
	{
		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $this->dashboard->getId(),
			'TYPE' => $this->dashboard->getType(),
			'OWNER_ID' => $this->dashboard->getField('OWNER_ID'),
		]);
		$accessController = AccessController::getCurrent();

		$canExport = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EXPORT, $accessItem);
		$this->arResult['CAN_EXPORT'] = $canExport ? 'Y' : 'N';

		$canEdit = false;
		if (
			$this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
			|| $this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
		)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_COPY, $accessItem);
		}
		else if ($this->dashboard->getType() === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$canEdit = $accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT, $accessItem);
		}
		$this->arResult['CAN_EDIT'] = $canEdit ? 'Y' : 'N';
	}
}
