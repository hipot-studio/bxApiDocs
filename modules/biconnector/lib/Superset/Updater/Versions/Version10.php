<?php

namespace Bitrix\BIConnector\Superset\Updater\Versions;

use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Cache\CacheManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Sets timezone.
 */
final class Version10 extends BaseVersion
{
	public function run(): Result
	{
		$result = new Result();

		if (SupersetInitializer::getSupersetStatus() !== SupersetInitializer::SUPERSET_STATUS_READY)
		{
			$result->addError(new Error('Superset status is not READY'));

			return $result;
		}

		$timezone = DataTimezone::getTimezone();
		if ($timezone)
		{
			$setTimezoneResult = Integrator::getInstance()->setTimezone($timezone);
			if ($setTimezoneResult->hasErrors())
			{
				$result->addErrors($setTimezoneResult->getErrors());

				return $result;
			}

			$clearCacheResult = CacheManager::getInstance()->clear();
			if (!$clearCacheResult->isSuccess())
			{
				$result->addErrors($clearCacheResult->getErrors());

				return $result;
			}
		}

		return $result;
	}
}
