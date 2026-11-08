<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Intranet\AnnualSummary;

use Bitrix\Crm\WebForm\Telemetry\Recorder;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\Date;
use RuntimeException;
use Throwable;

final class WebFormMetricSnapshotCache implements MetricSnapshotCacheInterface
{
	private const TTL = 7 * 24 * 60 * 60;
	private const CACHE_DIRECTORY = '/crm/webform/annual_summary/metrics';
	public const CACHE_VERSION = 2;

	private const LEGACY_METRIC_KEYS = [
		'createdForms',
		'views',
		'submissions',
		'abandonedFills',
	];

	public function getOrCalculate(Date $from, Date $to, callable $calculate): array
	{
		$cacheId = $this->cacheId($from, $to);
		$cache = Cache::createInstance();
		$cache->noOutput();
		if ($cache->initCache(self::TTL, $cacheId, self::CACHE_DIRECTORY))
		{
			$metrics = $this->normalize($cache->getVars());
			if ($metrics !== null)
			{
				return $metrics;
			}

			$cache->clean($cacheId, self::CACHE_DIRECTORY);
		}

		if (!$cache->startDataCache(self::TTL, $cacheId, self::CACHE_DIRECTORY))
		{
			$metrics = $this->normalize($cache->getVars());
			if ($metrics !== null)
			{
				return $metrics;
			}
		}

		try
		{
			$metrics = $this->normalize($calculate());
			if ($metrics === null)
			{
				throw new RuntimeException('CRM web form metric calculator returned an invalid snapshot');
			}

			$cache->endDataCache($metrics);

			return $metrics;
		}
		catch (Throwable $error)
		{
			$cache->abortDataCache();

			throw $error;
		}
	}

	public function clear(Date $from, Date $to): void
	{
		Cache::createInstance()->clean($this->cacheId($from, $to), self::CACHE_DIRECTORY);
	}

	private function cacheId(Date $from, Date $to): string
	{
		return 'v' . self::CACHE_VERSION . '_' . sha1(implode('_', [
			$from->format('Y-m-d'),
			$to->format('Y-m-d'),
			Option::get('crm', Recorder::TELEMETRY_START_DATE_OPTION, ''),
		]));
	}

	/**
	 * @return array<string, int>|null
	 */
	private function normalize(mixed $metrics): ?array
	{
		if (!is_array($metrics))
		{
			return null;
		}

		$normalized = [];
		foreach (self::LEGACY_METRIC_KEYS as $key)
		{
			if (!isset($metrics[$key]) || !is_int($metrics[$key]))
			{
				return null;
			}

			$normalized[$key] = $metrics[$key];
		}

		foreach (WebFormMetricCalculator::OPTIONAL_METRIC_KEYS as $key)
		{
			if (array_key_exists($key, $metrics) && is_int($metrics[$key]))
			{
				$normalized[$key] = $metrics[$key];
			}
		}

		return $normalized;
	}
}
