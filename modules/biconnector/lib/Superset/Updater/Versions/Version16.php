<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\Result;

/**
 * Delivers the market subscription expiration date that Superset refused before the retry agent existed.
 *
 * Always succeeds: a failed delivery is left to MarketSubscriptionSyncAgent, not to the next run of the updater.
 */
final class Version16 extends BaseVersion
{
	public function run(): Result
	{
		$manager = MarketAccessManager::getInstance();

		// Without it an empty expected date would be delivered as the start of the epoch.
		$manager->rememberActualExpirationDate();
		$manager->syncPendingExpirationDate();

		return new Result();
	}
}
