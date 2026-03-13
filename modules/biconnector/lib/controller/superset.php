<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\ActionFilter\BIConstructorAccess;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\BIConnector;

class Superset extends Controller
{
	public function getDefaultPreFilters()
	{
		$additionalFilters = [
			new BIConstructorAccess(),
		];

		if (Loader::includeModule('intranet'))
		{
			$additionalFilters[] = new IntranetUser();
		}

		return [
			...parent::getDefaultPreFilters(),
			...$additionalFilters,
		];
	}

	public function onStartupMetricSendAction(): void
	{
		\Bitrix\Main\Config\Option::set('biconnector', 'superset_startup_metric_send', true);
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
