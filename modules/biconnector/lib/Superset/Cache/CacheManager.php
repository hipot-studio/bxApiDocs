<?php

namespace Bitrix\BIConnector\Superset\Cache;

use Bitrix\BIConnector\Integration\Superset\Integrator\ProxyIntegrator;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;

final class CacheManager
{
	public const OPTION_LAST_CLEAR_TIME = 'superset_last_clear_cache_time';

	private static self $instance;

	public static function getInstance(): self
	{
		if (!isset(self::$instance))
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function clear(): Result
	{
		$result = new Result();

		$integrator = ProxyIntegrator::getInstance();
		$response = $integrator->clearCache();
		if ($response->hasErrors())
		{
			$result->addErrors($response->getErrors());
		}

		if ($result->isSuccess())
		{
			Option::set('biconnector', self::OPTION_LAST_CLEAR_TIME, time());
		}

		return $result;
	}
}
