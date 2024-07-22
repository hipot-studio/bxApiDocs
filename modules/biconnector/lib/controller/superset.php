<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

class Superset extends Controller
{
	public const SUPERSET_CLEAN_TIMESTAMP_OPTION = 'superset_clean_timestamp';

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

		\Bitrix\Main\Config\Option::set('biconnector', self::SUPERSET_CLEAN_TIMESTAMP_OPTION, time());
		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DELETED);

		return true;
	}

	public function enableAction(CurrentUser $currentUser): ?bool
	{
		if (!$currentUser->isAdmin() && !\CBitrix24::isPortalAdmin($currentUser->getId()))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_START_ERROR_RIGHTS')));

			return null;
		}

		$cleanTimestamp = (int)\Bitrix\Main\Config\Option::get('biconnector', self::SUPERSET_CLEAN_TIMESTAMP_OPTION, 0);
		$day = 60 * 60 * 24;
		if (($cleanTimestamp + $day) > time())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_START_ERROR_START_TIMESTAMP')));

			return null;
		}

		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		SupersetInitializer::startupSuperset();

		return true;
	}

	public function clearCacheAction(): ?array
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_ACCESS))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_CACHE_RIGHTS_ERROR')));

			return null;
		}

		$cacheManager = CacheManager::getInstance();
		if (!$cacheManager->canClearCache())
		{
			$time = $cacheManager->getNextClearTimeout();
			$errorMessage = Loc::getMessagePlural(
				'BICONNECTOR_CONTROLLER_SUPERSET_CACHE_TIMEOUT',
				ceil($time / 60),
				['#COUNT#' => ceil($time / 60)],
			);
			$this->addError(new Error($errorMessage));

			return null;
		}

		$clearResult = $cacheManager->clear();
		if (!$clearResult->isSuccess())
		{
			$this->addErrors($clearResult->getErrors());

			return null;
		}

		return [
			'timeoutToNextClearCache' => $cacheManager->getNextClearTimeout(),
		];
	}
}
