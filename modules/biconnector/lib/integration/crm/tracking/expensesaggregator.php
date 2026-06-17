<?php

namespace Bitrix\BIConnector\Integration\Crm\Tracking;

use Bitrix\BIConnector\Integration\Crm\Tracking\ExpensesProvider\Provider;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main;

final class ExpensesAggregator
{
	private const CACHE_TTL = 60 * 15;

	/**
	 * @var Provider[]
	 */
	private readonly array $providers;

	/**
	 * @param array $providers
	 */
	public function __construct(Provider ...$providers)
	{
		$this->providers = $providers;
	}

	/**
	 * @param Date|null $dateFrom
	 * @param Date|null $dateTo
	 * @return Result
	 */
	public function buildDailyExpensesReport(?Date $dateFrom, ?Date $dateTo): Main\Result
	{
		$result = new Main\Result();

		$expenses = [];
		/** @var Provider $provider */
		foreach ($this->providers as $provider)
		{
			$providerExpensesResult = $this->buildProviderDailyExpensesReport($provider, $dateFrom, $dateTo);
			if (!$providerExpensesResult->isSuccess())
			{
				$result->addErrors($providerExpensesResult->getErrors());

				return $result;
			}

			foreach ($providerExpensesResult->getData() as $expense)
			{
				$expenses[] = $expense;
			}
		}

		return $result->setData($expenses);
	}

	private function buildProviderDailyExpensesReport(Provider $provider, ?Date $dateFrom, ?Date $dateTo): Main\Result
	{
		$result = new Main\Result();

		$cacheDir = '/biconnector/integration/crm/dailyexpenses/';
		$cacheName = $this->getProviderRequestName($provider, $dateFrom, $dateTo);
		$cacheTtl = (int)(Main\Config\Option::get('biconnector', 'crm_daily_expenses_report_cache_ttl', null) ?? self::CACHE_TTL);
		$cache = Main\Data\Cache::createInstance();
		if ($cache->initCache($cacheTtl, $cacheName, $cacheDir))
		{
			return $result->setData($cache->getVars());
		}

		$providerExpensesResult = $provider->getDailyExpensesRows($dateFrom, $dateTo);
		if (!$providerExpensesResult->isSuccess())
		{
			return $result->addErrors($providerExpensesResult->getErrors());
		}

		$expenses = [];
		foreach (($providerExpensesResult->getData()['expenses'] ?? []) as $expense)
		{
			$expenses[] = $expense;
		}

		$cache->startDataCache();
		$cache->endDataCache($expenses);

		return $result->setData($expenses);
	}

	private function getProviderRequestName(Provider $provider, ?Date $dateFrom, ?Date $dateTo): string
	{
		$name = '';
		if ($dateFrom)
		{
			$name .= 'f:' . $dateFrom->getTimestamp() . '|';
		}

		if ($dateTo)
		{
			$name .= 't:' . $dateTo->getTimestamp() . '|';
		}

		$name .= 'p:' . $provider->getCacheKey();

		return $name;
	}
}
