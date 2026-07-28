<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;

final class Version11 extends BaseVersion
{
	public function run(): Result
	{
		$this->registerSupersetStatusChangeHandler();
		$this->registerSubscriptionRenewHandler();

		$manager = MarketAccessManager::getInstance();

		$manager->rememberActualExpirationDate();
		$manager->syncPendingExpirationDate();

		return new Result();
	}

	private function registerSupersetStatusChangeHandler(): void
	{
		$this->registerHandlerIfNotExists(
			'biconnector',
			SupersetInitializer::EVENT_ON_AFTER_SUPERSET_STATUS_CHANGE,
			'\Bitrix\BIConnector\Superset\MarketAccessManager',
			'onAfterSupersetStatusChange',
		);
	}

	private function registerSubscriptionRenewHandler(): void
	{
		$this->registerHandlerIfNotExists(
			'rest',
			'onSubscriptionRenew',
			'\Bitrix\BIConnector\Superset\MarketAccessManager',
			'onRestSubscriptionRenew',
		);
	}

	private function registerHandlerIfNotExists(string $fromModuleId, string $eventType, string $toClass, string $toMethod): void
	{
		$eventManager = EventManager::getInstance();
		$handlers = $eventManager->findEventHandlers($fromModuleId, $eventType, ['biconnector']);

		foreach ($handlers as $handler)
		{
			if (
				isset($handler['TO_MODULE_ID'], $handler['TO_CLASS'], $handler['TO_METHOD'])
				&& $handler['TO_MODULE_ID'] === 'biconnector'
				&& $handler['TO_CLASS'] === $toClass
				&& $handler['TO_METHOD'] === $toMethod
			)
			{
				return;
			}
		}

		$eventManager->registerEventHandler($fromModuleId, $eventType, 'biconnector', $toClass, $toMethod);
	}
}
