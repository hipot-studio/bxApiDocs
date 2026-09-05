<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

final class AppliedFilters
{
	private const MAX_PERIOD_DAYS = 366;

	/**
	 * Filter types whose value is a column predicate the assistant may pass
	 * back. Org structure is here because Superset resolves its nodes
	 * (`user_12`, `all_department_5`) into employee ids itself, checking them
	 * against the same structure dataset the on-screen selector uses.
	 */
	private const COLUMN_FILTER_TYPES = [
		'filter_select',
		'filter_company_structure',
		'filter_tasks_flow',
		'filter_bp_workflow_template',
	];

	public function __construct(private readonly int $userId)
	{
	}

	/**
	 * Validate caller-supplied filter names against dashboard's known filter
	 * columns BEFORE hitting the heavy /overview endpoint. Returns a Result
	 * with the unknown-name details on failure, no data on success.
	 */
	public function validate(Dto\Dashboard $dashboard, array $appliedFilters): Result
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

		$hasPeriod = false;
		foreach ($appliedFilters as $filter)
		{
			if (($filter['name'] ?? '') === 'period')
			{
				$periodCheck = self::checkPeriodWithinYearLimit($filter['value'] ?? null);
				if (!$periodCheck->isSuccess())
				{
					return $periodCheck;
				}

				$hasPeriod = true;
			}
		}

		if ($hasPeriod)
		{
			return $result->setData(['filters' => $appliedFilters]);
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
				$extraFormData['time_range'] =
					$value['from'] . ' : ' . self::shiftToExclusiveEnd((string)$value['to'])
				;

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
	private static function extractValidFilterColumns(Dto\Dashboard $dashboard): array
	{
		$valid = ['period'];
		foreach ($dashboard->nativeFilterConfig as $nf)
		{
			$type = $nf['filterType'] ?? '';
			if (in_array($type, self::COLUMN_FILTER_TYPES, true))
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

		// UTC on both bounds: in a zone with DST the distance between two local
		// midnights is not a whole number of days, so the day count below would
		// floor one day short and let a 367-day window through the limit.
		$from = strtotime($fromStr . ' UTC');
		$to = strtotime($toStr . ' UTC');
		// `strtotime("2026-02-30")` silently overflows to 2026-03-02 — the regex
		// above only checks shape. Round-trip back to a date string and compare
		// to reject impossible calendar dates explicitly.
		if ($from === false || gmdate('Y-m-d', $from) !== $fromStr)
		{
			return $result->addError(new Error(
				'Invalid period filter: `from` ("' . $fromStr . '") is not a real calendar date.',
				'invalid_period_calendar',
			));
		}
		if ($to === false || gmdate('Y-m-d', $to) !== $toStr)
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

		// Both bounds are inclusive and the end is later shifted to a half-open
		// `time_range`, so the window actually queried is one day longer than the
		// distance between them.
		$days = (int)(($to - $from) / 86400) + 1;
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
	 * Superset treats `time_range` as a half-open interval, so the last day of an
	 * inclusive range must be passed as the next day. The dashboard UI does the
	 * same shift ({@see EmbeddedFilter\DateTime}); without it the agent answer is
	 * one day short of the report.
	 *
	 * Applied here rather than in {@see resolve()} so that the period echoed back
	 * to the agent keeps the inclusive end the caller asked for.
	 */
	private static function shiftToExclusiveEnd(string $date): string
	{
		$timestamp = strtotime($date . ' +1 day');

		return $timestamp === false ? $date : date('Y-m-d', $timestamp);
	}

	/**
	 * TODO: reuse EmbeddedFilter\DateTime once getDateStart/getDateEnd return Date
	 * (currently typed `?string` but return Date — coerced to DD.MM.YYYY).
	 */
	private static function resolveDashboardDefaultPeriod(SupersetDashboard $dashboard): ?array
	{
		$period = $dashboard->getFilterPeriod();
		if ($period === '' || $period === null)
		{
			$period = EmbeddedFilter\DateTime::getDefaultPeriod();
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_NONE)
		{
			return null;
		}

		$today = new Date();

		if ($period === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			$from = $dashboard->getDateFilterStart() ?? EmbeddedFilter\DateTime::getDefaultDateStart();
			$to = $dashboard->getDateFilterEnd() ?? EmbeddedFilter\DateTime::getDefaultDateEnd();

			return [
				'from' => $from->format('Y-m-d'),
				'to' => $to->format('Y-m-d'),
			];
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_CURRENT_MONTH)
		{
			return [
				'from' => $today->format('Y-m-01'),
				'to' => $today->format('Y-m-t'),
			];
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_CURRENT_YEAR)
		{
			$year = (int)$today->format('Y');

			return [
				'from' => sprintf('%d-01-01', $year),
				'to' => sprintf('%d-12-31', $year),
			];
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_CURRENT_WEEK)
		{
			$weekDay = (int)$today->format('w');
			$weekDay = $weekDay === 0 ? 7 : $weekDay;
			$from = (clone $today)->add('-' . ($weekDay - 1) . ' days');
			$to = (clone $today)->add('+' . (7 - $weekDay) . ' days');

			return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
		}

		$lastDaysMap = [
			EmbeddedFilter\DateTime::PERIOD_LAST_7 => 7,
			EmbeddedFilter\DateTime::PERIOD_LAST_30 => 30,
			EmbeddedFilter\DateTime::PERIOD_LAST_90 => 90,
			EmbeddedFilter\DateTime::PERIOD_LAST_180 => 180,
			EmbeddedFilter\DateTime::PERIOD_LAST_365 => 365,
		];
		if (isset($lastDaysMap[$period]))
		{
			// The UI shifts the end for `range` and `current_*` only: a `last_N`
			// period goes to Superset as `[today−N : today)`, without today.
			// Its inclusive end is therefore yesterday, and the unconditional
			// shift in {@see convertToSupersetExtraFormData} restores exactly
			// the bound the dashboard uses.
			$from = (clone $today)->add('-' . $lastDaysMap[$period] . ' days');
			$to = (clone $today)->add('-1 day');

			return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
		}

		return null;
	}
}
