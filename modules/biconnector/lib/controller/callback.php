<?php

namespace Bitrix\BIConnector\Controller;

use Bitrix\BIConnector\Access\Install\AccessInstaller;
use Bitrix\BIConnector\Integration\Superset\Agent;
use Bitrix\BIConnector\Integration\Superset\Registrar;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\ActionFilter\ProxyAuth;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\Operation\OperationIntentService;
use Bitrix\BIConnector\Superset\SystemDashboardManager;
use Bitrix\BIConnector\Superset\UI\DashboardManager;
use Bitrix\Main\Application;
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

	public function configureActions(): array
	{
		return [
			// Answered without a stored token, so the action only reads the intents and writes nothing.
			'confirmOperationIntent' => [
				'-prefilters' => [ProxyAuth::class],
				'+prefilters' => [new ProxyAuth(acceptWithoutStoredToken: true)],
			],
		];
	}

	public function enableSupersetAction(): void
	{
		$context = Context::getCurrent();

		$responseBody = $context->getRequest()->getJsonList();
		$status = $responseBody->get('status');
		$generation = self::readString($responseBody->get('generation'));

		if (ConfigContainer::getConfigContainer()->isOutdatedServerGeneration($generation))
		{
			$this->logSkippedOutdatedCallback('enableSuperset', $generation);

			return;
		}

		if (!SupersetInitializer::isActivationCallbackApplicable())
		{
			$callbackStatus = is_string($status) ? $status : 'success';
			$this->logSkippedActivationCallback($callbackStatus);

			if ($callbackStatus !== 'error' && SupersetInitializer::isSupersetReady())
			{
				// The instance is up again (unfrozen, for instance). A date it refused meanwhile
				// has no other trigger: the portal status never left READY.
				Application::getInstance()->addBackgroundJob(static function (): void {
					MarketAccessManager::getInstance()->syncPendingExpirationDate();
				});
			}

			return;
		}

		if (isset($status) && $status === 'error')
		{
			$errorMsg = $responseBody->get('error') ?? 'Unknown server error';
			$error = new Error($errorMsg);
			SupersetInitializer::onUnsuccessfulSupersetStartup($error);

			return;
		}

		ConfigContainer::getConfigContainer()->setServerGeneration($generation);
		SupersetInitializer::enableSuperset($responseBody->get('superset_address') ?? '');
	}

	private function logSkippedActivationCallback(string $callbackStatus): void
	{
		SupersetInitializerLogger::logInfo('Superset activation callback skipped', [
			'callback_status' => $callbackStatus,
			'current_status' => SupersetInitializer::getSupersetStatus(),
			'rebind_required' => SupersetInitializer::isRebindRequired() ? 'Y' : 'N',
			'pending_delete' => SupersetInitializer::isSupersetPendingDelete() ? 'Y' : 'N',
		]);
	}

	private function logSkippedOutdatedCallback(string $callbackAction, string $callbackGeneration): void
	{
		SupersetInitializerLogger::logInfo('Superset callback of an outdated generation skipped', [
			'callback_action' => $callbackAction,
			'callback_generation' => $callbackGeneration,
			'current_generation' => ConfigContainer::getConfigContainer()->getServerGeneration(),
			'current_status' => SupersetInitializer::getSupersetStatus(),
		]);
	}

	public function freezeAction(): void
	{
		SupersetInitializerLogger::logInfo('Portal got freeze action', ['current_status' => SupersetInitializer::getSupersetStatus()]);

		ConfigContainer::getConfigContainer()->adoptServerGeneration(
			self::readString($this->getRequest()->getJsonList()->get('generation')),
		);
	}

	public function suspendAction(): void
	{
		$generation = self::readString($this->getRequest()->getJsonList()->get('generation'));
		if (ConfigContainer::getConfigContainer()->isOutdatedServerGeneration($generation))
		{
			$this->logSkippedOutdatedCallback('suspend', $generation);

			return;
		}

		ConfigContainer::getConfigContainer()->adoptServerGeneration($generation);

		if (SupersetInitializer::applySuspendCallback() === true)
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
		$generation = self::readString($responseBody?->get('generation'));
		if (ConfigContainer::getConfigContainer()->isOutdatedServerGeneration($generation))
		{
			$this->logSkippedOutdatedCallback('delete', $generation);

			return;
		}

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

	public function confirmOperationIntentAction(OperationIntentService $intentService): array
	{
		$payload = $this->getRequest()->getJsonList();

		$intent = $intentService->findConfirmed(
			self::readString($payload->get('operationId')),
			self::readString($payload->get('operation')),
			self::readString($payload->get('generation')),
			self::readString($payload->get('paramsHash')),
		);

		if ($intent === null)
		{
			return [
				'protocolSupported' => true,
				'confirmed' => false,
			];
		}

		return [
			'protocolSupported' => true,
			'confirmed' => true,
			'operationId' => (string)$intent['OPERATION_ID'],
			'operation' => (string)$intent['OPERATION'],
			'serverId' => self::readInt($payload->get('serverId')),
			'generation' => (string)$intent['GENERATION'],
			'paramsHash' => (string)$intent['PARAMS_HASH'],
		];
	}

	private static function readString(mixed $value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}

	private static function readInt(mixed $value): int
	{
		return is_numeric($value) ? (int)$value : 0;
	}
}
