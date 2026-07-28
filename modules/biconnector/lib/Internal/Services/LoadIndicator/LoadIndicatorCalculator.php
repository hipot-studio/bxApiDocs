<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\LoadIndicator;

use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\LoadCheckResult;
use Bitrix\BIConnector\Internal\Entity\ValueObject\LoadIndicator\TriggeredFactorInfo;
use Bitrix\BIConnector\Manager;
use Bitrix\Main\Application;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Json;

/**
 * Collects triggered load factors for one usage-log row (one query chunk).
 * Does not classify severity — that is the job of {@see LoadIndicator::createFromCheckResult()}.
 *
 * Short-circuits when REAL_TIME is below {@see Thresholds::SLOW_SECONDS}: no factors are
 * collected because in that case the level is decided regardless of secondary signals.
 *
 * Dataset column counts are cached in managed cache for {@see self::COLUMN_COUNT_CACHE_TTL}
 * seconds, keyed by SERVICE_ID + SOURCE_ID.
 */
final class LoadIndicatorCalculator
{
	private const COLUMN_COUNT_CACHE_TTL = 86400;

	private LoadCheckResult $result;

	/**
	 * @param array{
	 *     REAL_TIME?: float|null,
	 *     FIELDS?: string|null,
	 *     FILTERS?: string|null,
	 *     INPUT?: string|null,
	 *     ROW_NUM?: int|null,
	 *     DATA_SIZE?: int|null,
	 *     IS_OVER_LIMIT?: "Y"|"N"|null,
	 *     SERVICE_ID?: string|null,
	 *     SOURCE_ID?: string|null,
	 * } $row
	 *
	 * @return LoadCheckResult
	 */
	public function calculate(array $row): LoadCheckResult
	{
		$this->result = new LoadCheckResult();

		$isTimeFactorDetected = $this->detectTimeFactor((float)($row['REAL_TIME'] ?? 0));
		if (!$isTimeFactorDetected)
		{
			return $this->result;
		}

		$this->detectFilterFactors(
			(string)($row['FILTERS'] ?? ''),
			(string)($row['INPUT'] ?? ''),
		);
		$this->detectManyColumnsFactor(
			(string)($row['FIELDS'] ?? ''),
			(string)($row['SERVICE_ID'] ?? ''),
			(string)($row['SOURCE_ID'] ?? ''),
		);
		$this->detectLargeDataFactor($row);

		return $this->result;
	}

	/**
	 * @param float $time
	 *
	 * @return bool Whether is detected.
	 */
	private function detectTimeFactor(float $time): bool
	{
		if ($time < Thresholds::SLOW_SECONDS)
		{
			return false;
		}

		$this->result->addFactor(TriggeredFactorInfo::duration($time));

		return true;
	}

	private function detectFilterFactors(string $rawFilters, string $rawInput): void
	{
		$filters = $this->decodeJsonObject($rawFilters);
		$input = $this->decodeJsonObject($rawInput);

		if ($filters === null || $input === null)
		{
			return;
		}

		if ($filters === [])
		{
			$this->result->addFactor(TriggeredFactorInfo::noFilters());

			return;
		}

		$dateRange = $this->extractDateRange($input);
		if ($dateRange !== null)
		{
			$this->detectWidePeriodFactor($dateRange);
		}
	}

	/**
	 * @param array{dateRange?: array{startDate?: string, endDate?: string}} $input
	 *
	 * @return array{startDate?: Date, endDate?: Date}|null
	 */
	private function extractDateRange(array $input): ?array
	{
		if (!isset($input['dateRange']) || !is_array($input['dateRange']))
		{
			return null;
		}

		$range = $input['dateRange'];
		$rawStart = (string)($range['startDate'] ?? '');
		$rawEnd = (string)($range['endDate'] ?? '');

		if ($rawStart === '' && $rawEnd === '')
		{
			return null;
		}

		try
		{
			return [
				'startDate' => $rawStart !== '' ? new Date($rawStart, 'Y-m-d') : null,
				'endDate' => $rawEnd !== '' ? new Date($rawEnd, 'Y-m-d') : null,
			];
		}
		catch (\Bitrix\Main\ObjectException)
		{
			return null;
		}
	}

	/**
	 * @param array{startDate?: Date, endDate?: Date} $dateRange
	 *
	 * @return void
	 */
	private function detectWidePeriodFactor(array $dateRange): void
	{
		$startDate = $dateRange['startDate'] ?? null;
		$endDate = $dateRange['endDate'] ?? null;

		if (!($startDate && $endDate)) // Filled not both (one or none)
		{
			$this->result->addFactor(TriggeredFactorInfo::periodWide());

			return;
		}

		if ($endDate->getDiff($startDate)->days > Thresholds::PERIOD_WIDE_DAYS)
		{
			$this->result->addFactor(TriggeredFactorInfo::periodWide());
		}
	}

	private function detectManyColumnsFactor(string $rawFields, string $serviceId, string $sourceId): void
	{
		if (!$rawFields)
		{
			return;
		}

		$selectedFieldsCount = $this->getCountFieldsList($rawFields);
		if ($selectedFieldsCount === 0)
		{
			return;
		}

		$totalFieldsCount = $this->getTotalFieldsCount($serviceId, $sourceId);
		if ($totalFieldsCount <= Thresholds::MIN_COLUMNS_FOR_RATIO_FACTOR)
		{
			return;
		}

		$ratio = $selectedFieldsCount / $totalFieldsCount;
		if ($ratio < Thresholds::FIELD_RATIO_LIMIT)
		{
			return;
		}

		$this->result->addFactor(TriggeredFactorInfo::manyColumns($selectedFieldsCount, $totalFieldsCount));
	}

	/**
	 * @param string $rawFields
	 *
	 * @return int
	 */
	private function getCountFieldsList(string $rawFields): int
	{
		$trimmed = trim($rawFields);
		if ($trimmed === '')
		{
			return 0;
		}

		$splittedFieldNames = preg_split('/\s*,\s*/', $trimmed) ?: [];
		$fieldNames = array_values(array_filter($splittedFieldNames, static fn ($fieldName) => $fieldName !== ''));

		return count($fieldNames);
	}

	private function detectLargeDataFactor(array $row): void
	{
		if ((string)($row['IS_OVER_LIMIT'] ?? '') === 'Y')
		{
			$this->result->addFactor(TriggeredFactorInfo::largeData());

			return;
		}

		$dataSize = (int)($row['DATA_SIZE'] ?? 0);
		if ($dataSize >= Thresholds::LARGE_DATA_BYTES)
		{
			$this->result->addFactor(TriggeredFactorInfo::largeData());

			return;
		}

		$rowNum = (int)($row['ROW_NUM'] ?? 0);
		if ($rowNum >= Thresholds::LARGE_ROWS)
		{
			$this->result->addFactor(TriggeredFactorInfo::largeData());
		}
	}

	private function getTotalFieldsCount(string $serviceId, string $sourceId): int
	{
		if ($serviceId === '' || $sourceId === '')
		{
			return 0;
		}

		$cache = Application::getInstance()->getManagedCache();
		$cacheId = 'biconnector_load_indicator_columns_count_' . $serviceId . '_' . $sourceId;

		if ($cache->read(self::COLUMN_COUNT_CACHE_TTL, $cacheId))
		{
			$cachedCount = $cache->get($cacheId);

			return (int)$cachedCount;
		}

		$service = Manager::getInstance()->createService($serviceId);
		if (!$service)
		{
			$cache->set($cacheId, 0);

			return 0;
		}

		$fields = $service->getTableFields($sourceId);

		$count = count($fields);
		$cache->set($cacheId, $count);

		return $count;
	}

	private function decodeJsonObject(string $raw): ?array
	{
		if (!$raw)
		{
			return [];
		}

		try
		{
			$decoded = Json::decode($raw);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
