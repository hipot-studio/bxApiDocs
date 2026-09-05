<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketAccessManager;
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
}
