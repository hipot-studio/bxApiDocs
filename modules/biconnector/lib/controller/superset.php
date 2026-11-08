<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Access\Role\RoleTable;
use Bitrix\BIConnector\Integration\Superset\Integrator\ProxyIntegrator;
use Bitrix\BIConnector\Integration\Superset\Registrar;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\BIConnector\Superset\DomainLinkService;
use Bitrix\BIConnector\Superset\Error\IntegrationErrorPresenter;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\BIConnector\Superset\KeyManager;
use Bitrix\Main\Engine\Controller;
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

	public function isEnableConfirmationNeededAction(): array
	{
		return [
			'isNeeded' => SupersetInitializer::isSupersetPendingDelete() && SupersetInitializer::isSupersetInstanceExists(),
		];
	}

	public function clearCacheAction(): ?array
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SETTINGS_ACCESS))
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_CACHE_RIGHTS_ERROR');
			$this->addError(new UserFacingError($errorMessage));

			return null;
		}

		$cacheManager = CacheManager::getInstance();
		if (!$cacheManager->canClearCache())
		{
			$minutes = (int)ceil($cacheManager->getNextClearTimeout() / 60);
			$this->addError(UserFacingError::fromPhrasePlural(
				'BICONNECTOR_CONTROLLER_SUPERSET_CACHE_TIMEOUT',
				$minutes,
				['#COUNT#' => $minutes],
			));

			return null;
		}

		$clearResult = $cacheManager->clear();
		if (!$clearResult->isSuccess())
		{
			$this->addError(IntegrationErrorPresenter::present(
				$clearResult->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_CACHE_CLEAR_ERROR'),
			));

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
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_LOCAL_ERROR_ONLY_BOX');
			$this->addError(new UserFacingError($errorMessage));

			return;
		}

		if (!AccessController::getCurrent()->getUser()->isAdmin())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_DELETE_LOCAL_ERROR_RIGHTS');
			$this->addError(new UserFacingError($errorMessage));

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

	public function rebindSupersetAction(): void
	{
		if (!AccessController::getCurrent()->getUser()->isAdmin())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_REBIND_ERROR_RIGHTS');
			$this->addError(new UserFacingError($errorMessage));

			return;
		}

		if (!SupersetInitializer::isRebindRequired())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_REBIND_NOT_REQUIRED');
			$this->addError(new UserFacingError($errorMessage));

			return;
		}

		$rebindResult = Registrar::getRegistrar()->rebind();
		if (!$rebindResult->isSuccess())
		{
			$this->addError(IntegrationErrorPresenter::present(
				$rebindResult->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_REBIND_ERROR'),
			));

			return;
		}

		SupersetInitializer::startupSuperset();

		$accessKey = KeyManager::getAccessKey();
		if ($accessKey !== null)
		{
			ProxyIntegrator::getInstance()->changeBiconnectorToken($accessKey);
		}

		if (RoleTable::getCount() === 0)
		{
			AccessInstaller::install();
		}

		SupersetInitializer::clearRebindRequired();
	}

	public function linkAddressAction(): void
	{
		if (Loader::includeModule('bitrix24'))
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_LINK_ADDRESS_ERROR_ONLY_BOX');
			$this->addError(new UserFacingError($errorMessage));

			return;
		}

		if (!AccessController::getCurrent()->getUser()->isAdmin())
		{
			$errorMessage = Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_LINK_ADDRESS_ERROR_RIGHTS');
			$this->addError(new UserFacingError($errorMessage));

			return;
		}

		$result = DomainLinkService::getInstance()->linkAddress();
		if (!$result->isSuccess())
		{
			$this->addError(IntegrationErrorPresenter::present(
				$result->getErrors(),
				Loc::getMessage('BICONNECTOR_CONTROLLER_SUPERSET_LINK_ADDRESS_ERROR'),
			));
		}
	}
}
