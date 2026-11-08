<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Filter;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Model;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

final class AppliedFilters
{
	public const MAX_PERIOD_DAYS = 366;

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
	 * Resolve final applied_filters list with the default period filled in if
	 * the caller didn't pass one. Validates caller-supplied period.
	 *
	 * The default is filled in only for a dashboard whose period the portal resolves:
	 * a dashboard with its own default in the Superset builder gets no period from here,
	 * exactly as it gets none in the embedded url ({@see EmbeddedFilter\NativeFilterBuilder}).
	 * Superset applies that default itself when the request carries no period, so the
	 * numbers the assistant gets are the ones the dashboard shows. Which interval that
	 * was, the overview response does not tell: `meta.period` only echoes a period the
	 * portal has sent.
	 *
	 * On success, `getData()['filters']` holds the resolved list.
	 */
	public function resolve(
		Model\SupersetDashboard $dashboard,
		Dto\Dashboard $dashboardConfig,
		array $appliedFilters,
	): Result
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

		if ($hasPeriod || EmbeddedFilter\DateTime::isResolvedByDashboard($dashboardConfig->nativeFilterConfig))
		{
			return $result->setData(['filters' => $appliedFilters]);
		}

		$defaultPeriod = self::resolveDashboardDefaultPeriod($dashboard);
		$appliedFilters[] = [
			'name' => 'period',
			'value' => self::trimPeriodToYearLimit($defaultPeriod) ?? $defaultPeriod,
		];

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
	 * The period the dashboard resolves to at send time, in the same bounds the
	 * caller may pass. It comes from the global BI setting: it is what the dashboard
	 * itself is opened with once it has no period of its own. Bounds are taken from
	 * the same embedded filter the dashboard is opened with.
	 *
	 * @return array{from: string, to: string} Inclusive bounds.
	 */
	public static function resolveDashboardDefaultPeriod(Model\SupersetDashboard $dashboard): array
	{
		$dateFilter = new EmbeddedFilter\DateTime(new Model\Dashboard($dashboard));
		$from = $dateFilter->getDateStart();
		$to = clone($dateFilter->getDateEnd());

		// Calendar presets arrive with the end already shifted to the half-open
		// bound Superset expects, `range` bounds stay as the setting stores them.
		// The agent is answered with an inclusive period, and the shift back is
		// done by {@see convertToSupersetExtraFormData}.
		if ($dateFilter->getPeriod() !== EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			$to->add('-1 day');
		}

		return [
			'from' => $from->format('Y-m-d'),
			'to' => $to->format('Y-m-d'),
		];
	}

	/**
	 * Resolve a period explicitly selected outside the dashboard. An empty code
	 * leaves the period to Superset and therefore produces no portal-side bounds.
	 *
	 * @return array{from: string, to: string}|null
	 */
	public static function resolvePeriodCode(?string $period): ?array
	{
		if ($period === '' || $period === null)
		{
			return null;
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_NONE)
		{
			return null;
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_RANGE)
		{
			return [
				'from' => EmbeddedFilter\DateTime::getDefaultDateStart()->format('Y-m-d'),
				'to' => EmbeddedFilter\DateTime::getDefaultDateEnd()->format('Y-m-d'),
			];
		}

		$today = new Date();
		if ($period === EmbeddedFilter\DateTime::PERIOD_CURRENT_MONTH)
		{
			return [
				'from' => $today->format('Y-m-01'),
				'to' => $today->format('Y-m-t'),
			];
		}

		if ($period === EmbeddedFilter\DateTime::PERIOD_PREVIOUS_MONTH)
		{
			$from = (clone $today)->setDate((int)$today->format('Y'), (int)$today->format('m'), 1)->add('-1 month');
			$to = (clone $today)->setDate((int)$today->format('Y'), (int)$today->format('m'), 1)->add('-1 day');

			return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
		}

		if (
			$period === EmbeddedFilter\DateTime::PERIOD_CURRENT_WEEK
			|| $period === EmbeddedFilter\DateTime::PERIOD_PREVIOUS_WEEK
		)
		{
			$weekDay = (int)$today->format('N');
			$weekShift = $period === EmbeddedFilter\DateTime::PERIOD_PREVIOUS_WEEK ? -7 : 0;
			$from = (clone $today)->add(sprintf('%+d days', $weekShift - $weekDay + 1));
			$to = (clone $from)->add('+6 days');

			return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
		}

		if (
			$period === EmbeddedFilter\DateTime::PERIOD_CURRENT_YEAR
			|| $period === EmbeddedFilter\DateTime::PERIOD_PREVIOUS_YEAR
		)
		{
			$year = (int)$today->format('Y');
			if ($period === EmbeddedFilter\DateTime::PERIOD_PREVIOUS_YEAR)
			{
				$year--;
			}

			return [
				'from' => sprintf('%d-01-01', $year),
				'to' => sprintf('%d-12-31', $year),
			];
		}

		// Rolling codes belong to the digest selector contract. They remain valid
		// explicit overrides even though the global BI setting accepts only the
		// canonical calendar presets after Version14.
		$lastDays = [
			'last_7' => 7,
			'last_30' => 30,
			'last_90' => 90,
			'last_180' => 180,
			'last_365' => 365,
		][$period] ?? null;
		if ($lastDays !== null)
		{
			return [
				'from' => (clone $today)->add('-' . $lastDays . ' days')->format('Y-m-d'),
				'to' => (clone $today)->add('-1 day')->format('Y-m-d'),
			];
		}

		return null;
	}

	/**
	 * Narrows an inclusive period to the same limit enforced for caller-supplied filters.
	 * The end stays fixed so a historical report keeps the latest part of its configured range.
	 *
	 * @param array{from: string, to: string}|null $period
	 *
	 * @return array{from: string, to: string}|null Trimmed bounds, or null when no trimming is needed.
	 */
	public static function trimPeriodToYearLimit(?array $period): ?array
	{
		if ($period === null)
		{
			return null;
		}

		$from = self::parsePeriodDate($period['from'] ?? null);
		$to = self::parsePeriodDate($period['to'] ?? null);
		if ($from === null || $to === null || $to < $from)
		{
			return null;
		}

		$secondsInDay = 86400;
		$inclusiveDays = (int)(($to - $from) / $secondsInDay) + 1;
		if ($inclusiveDays <= self::MAX_PERIOD_DAYS)
		{
			return null;
		}

		$trimmedFrom = $to - (self::MAX_PERIOD_DAYS - 1) * $secondsInDay;

		return [
			'from' => gmdate('Y-m-d', $trimmedFrom),
			'to' => gmdate('Y-m-d', $to),
		];
	}

	private static function parsePeriodDate(mixed $date): ?int
	{
		if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
		{
			return null;
		}

		$timestamp = strtotime($date . ' UTC');
		if ($timestamp === false || gmdate('Y-m-d', $timestamp) !== $date)
		{
			return null;
		}

		return $timestamp;
	}
}
