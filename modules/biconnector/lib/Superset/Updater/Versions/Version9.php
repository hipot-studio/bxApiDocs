<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

/**
 * Sets subscription expiration date far into the future for portals where subscription is not available.
 */
final class Version9 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			$result->addError(new Error('Superset status is not READY'));

			return $result;
		}

		$marketAccessManager = MarketAccessManager::getInstance();

		if (!$marketAccessManager->isSubscriptionAccessible())
		{
			$farFutureDate = new Date('2099-12-31', 'Y-m-d');
			$marketAccessManager->updateExpirationDate($farFutureDate);
		}

		return $result;
	}
}
