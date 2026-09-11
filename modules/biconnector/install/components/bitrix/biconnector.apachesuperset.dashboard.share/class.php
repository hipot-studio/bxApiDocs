<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseLock;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;

final class BIConnectorSupersetDashboardPubComponent extends CBitrixComponent
{
	public function executeComponent()
	{
		Loc::loadMessages(__FILE__);

		$token = $this->arParams['TOKEN'] ?? '';
		$this->arResult['TOKEN'] = $token;
		$this->arResult['LANGUAGE_ID'] = CultureFormatter::getLanguageCode();

		// A public link is a dashboard too, so a finished term closes it as well. A guest has no section to be
		// sent to, so the answer is the same screen the component gives to a link that no longer works.
		if (SelfHostedLicenseLock::isDashboardLocked())
		{
			$this->arResult['STATUS'] = 'ERROR';
			$this->includeComponentTemplate();

			return;
		}

		$shareProvider = ServiceLocator::getInstance()->get('biconnector.provider.share');
		$share = $shareProvider->getByToken($token);
		if (!$share)
		{
			$this->arResult['STATUS'] = 'NOT_FOUND';
			$this->includeComponentTemplate();

			return;
		}

		if (!$share->isValid())
		{
			$this->arResult['STATUS'] = 'ERROR';
			$this->includeComponentTemplate();

			return;
		}

		$creatorId = $share->getCreatedById();
		if (!AccessController::getInstance($creatorId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE))
		{
			$this->arResult['STATUS'] = 'ERROR';
			$this->includeComponentTemplate();

			return;
		}

		$dashboard = SupersetDashboardTable::getById($share->getDashboardId())->fetchObject();
		if (!$dashboard)
		{
			$this->arResult['STATUS'] = 'NOT_FOUND';
			$this->includeComponentTemplate();

			return;
		}

		if ($dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		{
			$this->arResult['STATUS'] = 'NOT_FOUND';
			$this->includeComponentTemplate();

			return;
		}

		$this->arResult['DASHBOARD_TITLE'] = $dashboard->getTitle();
		$this->arResult['DASHBOARD_TYPE'] = mb_strtolower($dashboard->getType());
		$this->arResult['PULL_CONFIG'] = SharePullService::getPullConfig($token);

		$this->arResult['STATUS'] = 'PASSWORD_REQUIRED';
		$this->includeComponentTemplate();
	}
}
