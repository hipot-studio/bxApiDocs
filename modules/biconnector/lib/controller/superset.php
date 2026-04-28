<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Superset extends Controller
{
	public function getDefaultPreFilters()
	{
		$prefilters = parent::getDefaultPreFilters();
		if (Loader::includeModule('intranet'))
		{
			$prefilters[] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
		}

		return $prefilters;
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

	public function deleteLocalAction(bool $disableTool = false): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_LOCAL_ERROR_ONLY_BOX')));

			return;
		}

		if (!AccessController::getCurrent()->getUser()->isAdmin())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_LOCAL_ERROR_RIGHTS')));

			return;
		}

		SupersetInitializer::clearSupersetData();
		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		AccessInstaller::install();

		if ($disableTool && Loader::includeModule('intranet'))
		{
			(new \Bitrix\Intranet\Settings\Tools\BIConstructor())->disable(false);
		}
	}

	public function linkAddressAction(): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_LINK_ADDRESS_ERROR_ONLY_BOX')));

			return;
		}

		if (!AccessController::getCurrent()->getUser()->isAdmin())
		{
			$this->addError(new Error(Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_LINK_ADDRESS_ERROR_RIGHTS')));

			return;
		}

		$result = DomainLinkService::getInstance()->linkAddress();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}
}
