<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

class Superset extends Controller
{
	public function getDefaultPreFilters()
	{
		return [
			...parent::getDefaultPreFilters(),
			new \Bitrix\Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function onStartupMetricSendAction()
	{
		\Bitrix\Main\Config\Option::set('biconnector', 'superset_startup_metric_send', true);
	}

	/**
	 * Clean action from user disabling superset due to tariff restrictions.
	 *
	 * @param CurrentUser $currentUser
	 *
	 * @return bool|null
	 */
	public function cleanAction(CurrentUser $currentUser): ?bool
	{
		if (!$currentUser->isAdmin() && !\CBitrix24::isPortalAdmin($currentUser->getId()))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_ERROR_RIGHTS')));

			return null;
		}

		if (!SupersetInitializer::isSupersetExist())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_ALREADY_DELETED')));

			return null;
		}

		$result = SupersetInitializer::deleteInstance();
		if (!$result->isSuccess())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_ERROR')));

			return null;
		}

		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DELETED_BY_CLIENT);

		return true;
	}
}
