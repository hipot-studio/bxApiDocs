<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;

/**
 * Registers the subscription expiration even handler and refreshes market dashboard flags.
 */
final class Version8 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();

		$eventManager = EventManager::getInstance();
		$handlerExists = false;

		$handlers = $eventManager->findEventHandlers('rest', 'onSubscriptionRenew', ['biconnector']);

		foreach ($handlers as $handler)
		{
			if (
				isset($handler['TO_MODULE_ID'], $handler['TO_CLASS'], $handler['TO_METHOD'])
				&& $handler['TO_MODULE_ID'] === 'biconnector'
				&& $handler['TO_CLASS'] === '\Bitrix\BIConnector\Superset\MarketAccessManager'
				&& $handler['TO_METHOD'] === 'onRestSubscriptionRenew'
			)
			{
				$handlerExists = true;

				break;
			}
		}

		if (!$handlerExists)
		{
			$eventManager->registerEventHandler(
				'rest',
				'onSubscriptionRenew',
				'biconnector',
				'\Bitrix\BIConnector\Superset\MarketAccessManager',
				'onRestSubscriptionRenew',
			);
		}

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			$result->addError(new \Bitrix\Main\Error('Superset status is not READY'));

			return $result;
		}

		$marketAccessManager = MarketAccessManager::getInstance();

		if ($marketAccessManager->isSubscriptionAvailable())
		{
			$finalDate = $marketAccessManager->getSubscriptionFinalDate();
			$marketAccessManager->updateExpirationDate($finalDate);
		}
		$syncResult = $marketAccessManager->syncMarketDashboards();

		if (!$syncResult->isSuccess())
		{
			$result->addErrors($syncResult->getErrors());
		}

		return $result;
	}
}
