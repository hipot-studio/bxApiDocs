<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Engine\Controller;

class Callback extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new ProxyAuth(),
		];
	}

	public function enableSupersetAction(): void
	{
		$context = Context::getCurrent();

		$responseBody = $context->getRequest()->getJsonList();
		$status = $responseBody->get('status');

		if (isset($status) && $status === 'error')
		{
			$errorMsg = $responseBody->get('error') ?? 'Unknown server error';
			$error = new Error($errorMsg);
			SupersetInitializer::onUnsuccessfulSupersetStartup($error);

			return;
		}

		if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_LOAD)
		{
			SupersetInitializer::enableSuperset($responseBody->get('superset_address') ?? '');
		}
	}

	public function freezeAction(): void
	{
		SupersetInitializerLogger::logInfo('Portal got freeze action', ['current_status' => SupersetInitializer::getSupersetStatus()]);
	}

	/**
	 * @see \Bitrix\BIConnector\Integration\Superset\Agent::deleteInstance()
	 * @return void
	 */
	public function deleteAction(): void
	{
		$responseBody = Context::getCurrent()?->getRequest()->getJsonList();
		$status = $responseBody?->get('status');
		if ($status === 'error')
		{
			$errorMessage = $responseBody->get('error') ?? 'Unknown server error during deleting superset instance';
			SupersetInitializerLogger::logErrors([new Error($errorMessage)]);

			if (Option::get('biconnector', SupersetInitializer::ERROR_DELETE_INSTANCE_OPTION, 'N') === 'N')
			{
				\CAgent::addAgent(
					name: '\\Bitrix\\BIConnector\\Integration\\Superset\\Agent::deleteInstance();',
					module: 'biconnector',
					next_exec: convertTimeStamp(time() + \CTimeZone::getOffset() + 86400, 'FULL'),
				);
				Option::set('biconnector', SupersetInitializer::ERROR_DELETE_INSTANCE_OPTION, 'Y');
			}

			return;
		}

		// When deleting from proxy
		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_DELETED)
		{
			SupersetInitializer::clearSupersetData();
		}

		SupersetInitializerLogger::logInfo('Superset instance was deleted');

		SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
		AccessInstaller::install();
		Option::set('biconnector', \Bitrix\BIConnector\Configuration\Feature::CHECK_PERMISSION_BY_GROUP_OPTION, 'Y');
		SystemDashboardManager::actualizeSystemDashboards();

		DashboardManager::notifySupersetStatus(SupersetInitializer::SUPERSET_STATUS_DOESNT_EXISTS);
	}
}
