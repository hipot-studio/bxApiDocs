<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Services\Share\GuestAccessState;
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
		// sent to, and a term is a state of the portal rather than of the link: the answer is the screen of a
		// report that does not open, not the one of a link that is dead.
		if (SelfHostedLicenseLock::isDashboardLocked())
		{
			$this->refuse('ERROR', GuestAccessState::ReportUnavailable);

			return;
		}

		$shareProvider = ServiceLocator::getInstance()->get('biconnector.provider.share');
		$share = $shareProvider->getByToken($token);
		if (!$share)
		{
			$this->refuse('NOT_FOUND', GuestAccessState::LinkInvalid);

			return;
		}

		$banRepository = ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
		if (!$share->isValid() || $banRepository->isBanned($share->getDashboardId()))
		{
			$this->refuse('ERROR', GuestAccessState::LinkInvalid);

			return;
		}

		$creatorId = $share->getCreatedById();
		if (!AccessController::getInstance($creatorId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE))
		{
			$this->refuse('ERROR', GuestAccessState::LinkInvalid);

			return;
		}

		$dashboard = SupersetDashboardTable::getById($share->getDashboardId())->fetchObject();
		if (!$dashboard)
		{
			$this->refuse('NOT_FOUND', GuestAccessState::LinkInvalid);

			return;
		}

		if ($dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT)
		{
			$this->refuse('NOT_FOUND', GuestAccessState::LinkInvalid);

			return;
		}

		$this->arResult['DASHBOARD_TITLE'] = $dashboard->getTitle();
		$this->arResult['DASHBOARD_TYPE'] = mb_strtolower($dashboard->getType());
		$this->arResult['PULL_CONFIG'] = SharePullService::getPullConfig($token);

		$this->arResult['STATUS'] = 'PASSWORD_REQUIRED';
		$this->includeComponentTemplate();
	}

	/**
	 * A guest is shown one of two screens, and the state tells which one: the status alone does not, because
	 * both a dead link and a temporary failure can arrive with the same status.
	 */
	private function refuse(string $status, GuestAccessState $state): void
	{
		$this->arResult['STATUS'] = $status;
		$this->arResult['STATE'] = $state->value;
		$this->includeComponentTemplate();
	}
}
