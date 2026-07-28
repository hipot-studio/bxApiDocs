<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\Agent;
use Bitrix\BIConnector\Integration\Superset\Registrar;
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

	public function suspendAction(): void
	{
		$currentStatus = SupersetInitializer::getSupersetStatus();
		SupersetInitializerLogger::logInfo('Portal got suspend action', ['current_status' => $currentStatus]);

		SupersetInitializer::markSupersetInstanceSuspended(true);

		// suspend finished. Promote status so cancelPendingDelete() knows
		// it is safe to call resume immediately if the user re-enables BI later.
		if ($currentStatus === SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE)
		{
			SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED);

			return;
		}

		// User already re-enabled BI — cancelPendingDelete() set status to LOAD and
		// deferred resume. suspend is done now, so trigger resume.
		if ($currentStatus === SupersetInitializer::SUPERSET_STATUS_LOAD)
		{
			SupersetInitializer::resumeSuperset(['reason' => SupersetInitializer::FREEZE_REASON_PENDING_DELETE]);
		}
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
			$errorMessage = $responseBody?->get('error') ?? 'Unknown server error during deleting superset instance';
			SupersetInitializerLogger::logErrors([new Error($errorMessage)]);

			if (Option::get('biconnector', SupersetInitializer::ERROR_DELETE_INSTANCE_OPTION, 'N') === 'N')
			{
				\CAgent::addAgent(
					name: 'Bitrix\\BIConnector\\Integration\\Superset\\Agent::deleteInstance();',
					module: 'biconnector',
					next_exec: convertTimeStamp(time() + \CTimeZone::getOffset() + 86400, 'FULL'),
				);
				Option::set('biconnector', SupersetInitializer::ERROR_DELETE_INSTANCE_OPTION, 'Y');
			}

			return;
		}

		if (SupersetInitializer::isSupersetPendingDelete())
		{
			Agent::notifyPendingDeleteCompleted();
		}

		Registrar::getRegistrar()->clear(__CLASS__ . '::' . __FUNCTION__);
		\CAgent::RemoveAgent(Agent::class . '::recoverDeletedAfterRebindTimeout();', 'biconnector');
		SupersetInitializer::fixDeleteTimestamp();
		SupersetInitializer::markSupersetInstanceExists(false);

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
