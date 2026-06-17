<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\Dashboard as DashboardDto;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter\DateTime as DashboardDateFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

final class AppliedFilters
{
	private const MAX_PERIOD_DAYS = 366;

	public function __construct(private readonly int $userId)
	{
	}

	/**
	 * Validate caller-supplied filter names against dashboard's known filter
	 * columns BEFORE hitting the heavy /overview endpoint. Returns a Result
	 * with the unknown-name details on failure, no data on success.
	 */
	public function validate(DashboardDto $dashboard, array $appliedFilters): Result
	{
		$result = new Result();
		$validColumns = self::extractValidFilterColumns($dashboard);

		$unknown = [];
		foreach ($appliedFilters as $filter)
		{
			$name = (string)($filter['name'] ?? '');
			if ($name === '' || isset($unknown[$name]))
			{
				continue;
			}
			if (!in_array($name, $validColumns, true))
			{
				$unknown[$name] = [
					'name' => $name,
					'suggested' => self::suggestClosestFilterName($name, $validColumns),
				];
			}
		}

		if (empty($unknown))
		{
			return $result;
		}

		$result->addError(new Error(
			'Unknown filter names: ' . implode(', ', array_keys($unknown)),
			'unknown_filter_names',
			[
				'error' => 'unknown_filter_names',
				'unknown' => array_values($unknown),
				'validFilterColumns' => array_values($validColumns),
				'instruction' => 'One or more applied filter names do not match any dashboard filter. '
					. 'If `suggested` is non-null, it is the closest valid filter by edit distance — '
					. 'prefer it over re-prompting the user. Otherwise pick a column from validFilterColumns '
					. '(or "period" for date range).',
			],
		));

		return $result;
	}

	/**
	 * Resolve final applied_filters list with the dashboard's default period
	 * filled in if the caller didn't pass one. Validates caller-supplied period.
	 *
	 * On success, `getData()['filters']` holds the resolved list.
	 */
	public function resolve(SupersetDashboard $dashboard, array $appliedFilters): Result
	{
		$result = new Result();

		foreach ($appliedFilters as $filter)
		{
			if (($filter['name'] ?? '') === 'period')
			{
				$periodCheck = self::checkPeriodWithinYearLimit($filter['value'] ?? null);
				if (!$periodCheck->isSuccess())
				{
					return $periodCheck;
				}

				return $result->setData(['filters' => $appliedFilters]);
			}
		}

		$resolved = self::resolveDashboardDefaultPeriod($dashboard);
		if ($resolved !== null)
		{
			$appliedFilters[] = [
				'name' => 'period',
				'value' => $resolved,
			];
		}

		return $result->setData(['filters' => $appliedFilters]);
	}

	/**
	 * Convert resolved `[{name, value}]` filters into Superset's extraFormData
	 * shape consumed by `BitrixDashboardRestApi::_inject_filters`. Pure
	 * conversion — caller is expected to feed validated data (see {@see resolve}).
	 */
	public function convertToSupersetExtraFormData(array $appliedFilters): array
	{
		$extraFormData = [];
		$columnFilters = [];

		foreach ($appliedFilters as $filter)
		{
			$name = $filter['name'] ?? '';
			$value = $filter['value'] ?? null;

			if ($name === '')
			{
				continue;
			}

			if ($name === 'period' && is_array($value) && !empty($value['from']) && !empty($value['to']))
			{
				$extraFormData['time_range'] = $value['from'] . ' : ' . $value['to'];

				continue;
			}

			$val = is_array($value) ? array_values($value) : [$value];
			$val = array_values(array_filter($val, static fn($v) => $v !== null && $v !== ''));
			if (empty($val))
			{
				continue;
			}

			$columnFilters[] = [
				'col' => $name,
				'op' => 'IN',
				'val' => $val,
			];
		}

		if (!empty($columnFilters))
		{
			$extraFormData['filters'] = $columnFilters;
		}

		if (empty($extraFormData))
		{
			return [];
		}

		return [['extraFormData' => $extraFormData]];
	}

	/**
	 * @return string[]
	 */
	private static function extractValidFilterColumns(DashboardDto $dashboard): array
	{
		$valid = ['period'];
		foreach ($dashboard->nativeFilterConfig as $nf)
		{
			$type = $nf['filterType'] ?? '';
			if ($type === 'filter_select')
			{
				$col = $nf['targets'][0]['column']['name'] ?? null;
				if (is_string($col) && $col !== '')
				{
					$valid[] = $col;
				}
			}
			elseif ($type === 'filter_timegrain')
			{
				$valid[] = 'time_grain_sqla';
			}
		}

		return array_values(array_unique($valid));
	}

	private static function suggestClosestFilterName(string $name, array $valid): ?string
	{
		$nameLen = strlen($name);
		if ($nameLen === 0 || empty($valid))
		{
			return null;
		}

		$cutoff = max(2, min(4, (int)ceil($nameLen / 3)));
		$bestDistance = PHP_INT_MAX;
		$bestName = null;
		foreach ($valid as $candidate)
		{
			$d = levenshtein($name, (string)$candidate);
			if ($d < $bestDistance)
			{
				$bestDistance = $d;
				$bestName = (string)$candidate;
			}
		}

		if ($bestDistance > $cutoff)
		{
			return null;
		}

		return $bestName;
	}

	private static function checkPeriodWithinYearLimit(mixed $value): Result
	{
		$result = new Result();

		if (!is_array($value) || empty($value['from']) || empty($value['to']))
		{
			return $result->addError(new Error(
				'Invalid period filter: expected {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}.',
				'invalid_period_shape',
			));
		}

		$fromStr = (string)$value['from'];
		$toStr = (string)$value['to'];
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr))
		{
			return $result->addError(new Error(
				'Invalid period filter: `from` and `to` must be ISO-8601 dates (YYYY-MM-DD).',
				'invalid_period_format',
			));
		}

		$from = strtotime($fromStr);
		$to = strtotime($toStr);
		// `strtotime("2026-02-30")` silently overflows to 2026-03-02 — the regex
		// above only checks shape. Round-trip back to a date string and compare
		// to reject impossible calendar dates explicitly.
		if ($from === false || date('Y-m-d', $from) !== $fromStr)
		{
			return $result->addError(new Error(
				'Invalid period filter: `from` ("' . $fromStr . '") is not a real calendar date.',
				'invalid_period_calendar',
			));
		}
		if ($to === false || date('Y-m-d', $to) !== $toStr)
		{
			return $result->addError(new Error(
				'Invalid period filter: `to` ("' . $toStr . '") is not a real calendar date.',
				'invalid_period_calendar',
			));
		}
		if ($to < $from)
		{
			return $result->addError(new Error(
				'Invalid period filter: `from` must be not later than `to`.',
				'invalid_period_reversed',
			));
		}

		$days = (int)(($to - $from) / 86400);
		if ($days > self::MAX_PERIOD_DAYS)
		{
			return $result->addError(new Error(
				'Period exceeds 1 year (' . $days . ' days). '
				. 'Narrow the range to at most ' . self::MAX_PERIOD_DAYS . ' days — long ranges '
				. 'hit the server timeout and produce empty responses.',
				'period_too_long',
			));
		}

		return $result;
	}

	/**
	 * TODO: reuse DashboardDateFilter once getDateStart/getDateEnd return Date
	 * (currently typed `?string` but return Date — coerced to DD.MM.YYYY).
	 */
	private static function resolveDashboardDefaultPeriod(SupersetDashboard $dashboard): ?array
	{
		$period = $dashboard->getFilterPeriod();
		if ($period === '' || $period === null)
		{
			$period = DashboardDateFilter::getDefaultPeriod();
		}

		if ($period === DashboardDateFilter::PERIOD_NONE)
		{
			return null;
		}

		$today = new Date();

		if ($period === DashboardDateFilter::PERIOD_RANGE)
		{
			$from = $dashboard->getDateFilterStart();
			$to = $dashboard->getDateFilterEnd();
			if (!$from instanceof Date || !$to instanceof Date)
			{
				return null;
			}

			return [
				'from' => $from->format('Y-m-d'),
				'to' => $to->format('Y-m-d'),
			];
		}

		if ($period === DashboardDateFilter::PERIOD_CURRENT_MONTH)
		{
			return [
				'from' => $today->format('Y-m-01'),
				'to' => $today->format('Y-m-t'),
			];
		}

		if ($period === DashboardDateFilter::PERIOD_CURRENT_YEAR)
		{
			$year = (int)$today->format('Y');

			return [
				'from' => sprintf('%d-01-01', $year),
				'to' => sprintf('%d-12-31', $year),
			];
		}

		if ($period === DashboardDateFilter::PERIOD_CURRENT_WEEK)
		{
			$weekDay = (int)$today->format('w');
			$weekDay = $weekDay === 0 ? 7 : $weekDay;
			$from = (clone $today)->add('-' . ($weekDay - 1) . ' days');
			$to = (clone $today)->add('+' . (7 - $weekDay) . ' days');

			return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
		}

		$lastDaysMap = [
			DashboardDateFilter::PERIOD_LAST_7 => 7,
			DashboardDateFilter::PERIOD_LAST_30 => 30,
			DashboardDateFilter::PERIOD_LAST_90 => 90,
			DashboardDateFilter::PERIOD_LAST_180 => 180,
			DashboardDateFilter::PERIOD_LAST_365 => 365,
		];
		if (isset($lastDaysMap[$period]))
		{
			$from = (clone $today)->add('-' . $lastDaysMap[$period] . ' days');

			return ['from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
		}

		return null;
	}
}
