<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\V2\Statistics\Dto\StatResult;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Aggregated per-line statistics for a period for imopenlines.v2.Stat.get.
 *
 * All aggregates are computed with a handful of grouped ORM queries over
 * SessionTable, sharing one base filter (period + configId/source/operatorId
 * filters + operator visibility). Every numeric metric defaults to 0 (not null)
 * when there is no matching data, per the phase 3 contract.
 */
final class StatService
{
	private const MAX_PERIOD_DAYS = 366;

	public const ERROR_PERIOD_REQUIRED = 'PERIOD_REQUIRED';
	public const ERROR_PERIOD_TOO_LARGE = 'PERIOD_TOO_LARGE';
	public const ERROR_INVALID_FILTER = 'INVALID_FILTER';

	public function getStat(int $userId, ?string $dateFrom, ?string $dateTo, array $filter): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if ($dateFrom === null || $dateFrom === '' || $dateTo === null || $dateTo === '')
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_PERIOD_REQUIRED'), self::ERROR_PERIOD_REQUIRED));

			return $result;
		}

		$from = $this->parseDate($dateFrom);
		$to = $this->parseDate($dateTo);

		if ($from === null || $to === null)
		{
			// dateFrom/dateTo were supplied but do not parse as dates: this is a malformed
			// value, not a missing required parameter, so report it distinctly instead of
			// reusing PERIOD_REQUIRED (which would mislead the client into thinking the
			// parameters were absent).
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_STAT_INVALID_DATE_VALUE'), self::ERROR_INVALID_FILTER));

			return $result;
		}

		// Calendar-day diff() avoids a false PERIOD_TOO_LARGE on a DST transition, where an
		// allowed 366-calendar-day span is 366 days + 1h in raw seconds.
		$interval = $from->getDiff($to);
		if ($interval->invert === 1)
		{
			// dateFrom is later than dateTo. Left as-is the >=DATE_CREATE / <=DATE_CREATE
			// filter would just return empty stats; reject the inverted range as a bad filter.
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_STAT_INVALID_PERIOD_ORDER'), self::ERROR_INVALID_FILTER));

			return $result;
		}

		if ($interval->days > self::MAX_PERIOD_DAYS)
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_PERIOD_TOO_LARGE'), self::ERROR_PERIOD_TOO_LARGE));

			return $result;
		}

		$filter = $this->normalizeListFilters($filter, $result);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$ormFilter = $this->buildOrmFilter($userId, $from, $to, $filter);

		if ($ormFilter === null)
		{
			// No visibility at all: every metric is empty/zero, not an error.
			$result->setData((new StatResult(
				0, 0, 0, 0.0, 0.0, 0, 0, 0, 0.0, 0, 0, [], $this->emptyHours(), [],
			))->toRestFormat());

			return $result;
		}

		$aggregates = $this->loadAggregates($ormFilter);
		$bySource = $this->loadSessionsBySource($ormFilter);
		$byHour = $this->loadSessionsByHour($ormFilter);
		$byOperator = $this->loadSessionsByOperator($ormFilter);

		$votedSessions = $aggregates['like'] + $aggregates['dislike'];
		$positiveRate = $votedSessions > 0 ? $aggregates['like'] / $votedSessions : 0.0;

		$statResult = new StatResult(
			$aggregates['total'],
			$aggregates['closed'],
			$aggregates['spam'],
			$aggregates['avgWaitAnswer'],
			$aggregates['avgSessionDuration'],
			$aggregates['like'],
			$aggregates['dislike'],
			$votedSessions,
			$positiveRate,
			$aggregates['kpiOk'],
			$aggregates['kpiFail'],
			$bySource,
			$byHour,
			$byOperator,
		);

		$result->setData($statResult->toRestFormat());

		return $result;
	}

	/**
	 * Normalizes the list filters (configId/operatorId to positive ints, source to
	 * non-empty strings), dedupes them, and rejects any list exceeding
	 * {@see FilterNormalizer::MAX_LIST_SIZE}. A provided list that normalizes to empty
	 * (e.g. [0] or a non-numeric id) is reported as INVALID_FILTER rather than silently
	 * dropped, so an explicit filter never broadens the query to all rows.
	 */
	private function normalizeListFilters(array $filter, Result $result): array
	{
		foreach (['configId', 'operatorId', 'source'] as $key)
		{
			if (!array_key_exists($key, $filter))
			{
				continue;
			}

			$values = $key === 'source'
				? FilterNormalizer::stringList($filter[$key])
				: FilterNormalizer::idList($filter[$key]);

			if (FilterNormalizer::exceedsLimit($values))
			{
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_STAT_FILTER_LIST_TOO_LARGE', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			// The key was provided (array_key_exists above) but no value survived
			// normalization (e.g. [0] or a non-numeric id): reject it as an invalid filter
			// rather than silently dropping it, which would broaden the query to all rows.
			if (empty($values))
			{
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_STAT_EMPTY_FILTER_LIST', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			$filter[$key] = $values;
		}

		return $filter;
	}

	/**
	 * @return array|null The ORM filter, or null when the current user has no
	 *                     visibility at all (empty allowed-operators set).
	 */
	private function buildOrmFilter(int $userId, DateTime $from, DateTime $to, array $filter): ?array
	{
		$ormFilter = [
			'>=DATE_CREATE' => $from,
			'<=DATE_CREATE' => $to,
		];

		if (isset($filter['configId']))
		{
			$ormFilter['=CONFIG_ID'] = $filter['configId'];
		}

		if (isset($filter['source']))
		{
			$ormFilter['=SOURCE'] = $filter['source'];
		}

		if (isset($filter['operatorId']))
		{
			$ormFilter['=OPERATOR_ID'] = $filter['operatorId'];
		}

		$allowedOperatorIds = AllowedOperators::getAllowedOperatorIds($userId);
		if (is_array($allowedOperatorIds))
		{
			if (empty($allowedOperatorIds))
			{
				return null;
			}

			$existing = $ormFilter['=OPERATOR_ID'] ?? null;
			$merged = $existing === null
				? $allowedOperatorIds
				: array_values(array_intersect((array)$existing, $allowedOperatorIds));

			if (empty($merged))
			{
				return null;
			}

			$ormFilter['=OPERATOR_ID'] = $merged;
		}

		return $ormFilter;
	}

	/**
	 * Aggregates totals + vote counts + KPI counts in a single ungrouped SessionTable
	 * query instead of three (this method replaces the former loadTotals()/
	 * loadVoteCounts()/loadKpiCounts() - see review round 5 suggestion #7). All three
	 * are plain `SELECT ... FROM b_imopenlines_session WHERE <same $ormFilter>` with no
	 * GROUP BY, so they can share one scan; only bySource/byHour/byOperator stay
	 * separate because they have their own GROUP BY.
	 *
	 * The KPI conditions previously relied on an extra `CONFIG.KPI_FIRST_ANSWER_TIME >
	 * 0` filter predicate (restricting the whole query, including TOTAL/CLOSED/etc., to
	 * lines with a KPI configured) to be correct; here that same guard is inlined into
	 * each KPI CASE expression instead ({@see self::loadAggregates()}'s
	 * KPI_OK_COUNT/KPI_FAIL_COUNT), so the single shared $ormFilter (without the KPI
	 * predicate) still yields identical TOTAL/CLOSED/SPAM/AVG_* and KPI_* values to the
	 * previous two separately-filtered queries.
	 *
	 * @return array{total:int, closed:int, spam:int, avgWaitAnswer:float, avgSessionDuration:float, like:int, dislike:int, kpiOk:int, kpiFail:int}
	 */
	private function loadAggregates(array $ormFilter): array
	{
		$row = SessionTable::getList([
			'select' => [
				'TOTAL',
				'CLOSED_COUNT',
				'SPAM_COUNT',
				'AVG_WAIT_ANSWER',
				'AVG_SESSION_DURATION',
				'LIKE_COUNT',
				'DISLIKE_COUNT',
				'KPI_OK_COUNT',
				'KPI_FAIL_COUNT',
			],
			'filter' => $ormFilter,
			'runtime' => [
				new ExpressionField('TOTAL', 'COUNT(*)'),
				// "Closed" must match how Session.list / Dictionary::getSessionStatus() serialize
				// a `closed` session: not spam, and either CLOSED='Y' or a closing STATUS
				// (STATUS_CLOSE/SILENTLY_CLOSE/DUPLICATE). A plain CLOSED='Y' count would both
				// include spam-closed rows (double-counted against SPAM_COUNT) and miss
				// closing-STATUS rows that still carry CLOSED='N', diverging from the list.
				new ExpressionField(
					'CLOSED_COUNT',
					'SUM(CASE WHEN %s != \'Y\' AND (%s = \'Y\' OR %s IN ('
						. Session::STATUS_CLOSE . ', ' . Session::STATUS_SILENTLY_CLOSE . ', ' . Session::STATUS_DUPLICATE
						. ')) THEN 1 ELSE 0 END)',
					['SPAM', 'CLOSED', 'STATUS']
				),
				new ExpressionField('SPAM_COUNT', 'SUM(CASE WHEN %s = \'Y\' THEN 1 ELSE 0 END)', ['SPAM']),
				new ExpressionField(
					'AVG_WAIT_ANSWER',
					'AVG(CASE WHEN %s IS NOT NULL AND %s > 0 THEN %s ELSE NULL END)',
					['TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER']
				),
				new ExpressionField(
					'AVG_SESSION_DURATION',
					'AVG(CASE WHEN %s IS NOT NULL AND %s > 0 THEN %s ELSE NULL END)',
					['TIME_DIALOG', 'TIME_DIALOG', 'TIME_DIALOG']
				),
				new ExpressionField('LIKE_COUNT', 'SUM(CASE WHEN %s = ' . Session::VOTE_LIKE . ' THEN 1 ELSE 0 END)', ['VOTE']),
				new ExpressionField('DISLIKE_COUNT', 'SUM(CASE WHEN %s = ' . Session::VOTE_DISLIKE . ' THEN 1 ELSE 0 END)', ['VOTE']),
				new ExpressionField(
					'KPI_OK_COUNT',
					'SUM(CASE WHEN %s > 0 AND %s > 0 AND %s <= %s THEN 1 ELSE 0 END)',
					['CONFIG.KPI_FIRST_ANSWER_TIME', 'TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER', 'CONFIG.KPI_FIRST_ANSWER_TIME']
				),
				new ExpressionField(
					'KPI_FAIL_COUNT',
					'SUM(CASE WHEN %s > 0 AND %s > %s THEN 1 ELSE 0 END)',
					['CONFIG.KPI_FIRST_ANSWER_TIME', 'TIME_FIRST_ANSWER', 'CONFIG.KPI_FIRST_ANSWER_TIME']
				),
			],
		])->fetch();

		if ($row === false)
		{
			return [
				'total' => 0,
				'closed' => 0,
				'spam' => 0,
				'avgWaitAnswer' => 0.0,
				'avgSessionDuration' => 0.0,
				'like' => 0,
				'dislike' => 0,
				'kpiOk' => 0,
				'kpiFail' => 0,
			];
		}

		return [
			'total' => (int)($row['TOTAL'] ?? 0),
			'closed' => (int)($row['CLOSED_COUNT'] ?? 0),
			'spam' => (int)($row['SPAM_COUNT'] ?? 0),
			'avgWaitAnswer' => (float)($row['AVG_WAIT_ANSWER'] ?? 0.0),
			'avgSessionDuration' => (float)($row['AVG_SESSION_DURATION'] ?? 0.0),
			'like' => (int)($row['LIKE_COUNT'] ?? 0),
			'dislike' => (int)($row['DISLIKE_COUNT'] ?? 0),
			'kpiOk' => (int)($row['KPI_OK_COUNT'] ?? 0),
			'kpiFail' => (int)($row['KPI_FAIL_COUNT'] ?? 0),
		];
	}

	/**
	 * @return array<int, array{source:string, count:int}>
	 */
	private function loadSessionsBySource(array $ormFilter): array
	{
		$rows = SessionTable::getList([
			'select' => ['SOURCE', 'CNT'],
			'filter' => $ormFilter,
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['SOURCE'],
			'order' => ['CNT' => 'DESC'],
		])->fetchAll();

		$items = [];
		foreach ($rows as $row)
		{
			$source = (string)($row['SOURCE'] ?? '');
			if ($source === '')
			{
				continue;
			}

			$items[] = [
				'source' => $source,
				'count' => (int)$row['CNT'],
			];
		}

		return $items;
	}

	/**
	 * @return int[] Exactly 24 entries (hours 0..23 in the server's local time zone,
	 *               same as HOUR() applied to the stored DATE_CREATE column), 0 where
	 *               there is no data.
	 */
	private function loadSessionsByHour(array $ormFilter): array
	{
		$rows = SessionTable::getList([
			'select' => ['HOUR_OF_DAY', 'CNT'],
			'filter' => $ormFilter,
			'runtime' => [
				new ExpressionField('HOUR_OF_DAY', $this->getHourExpression(), ['DATE_CREATE']),
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['HOUR_OF_DAY'],
		])->fetchAll();

		$hours = $this->emptyHours();
		foreach ($rows as $row)
		{
			$hour = (int)($row['HOUR_OF_DAY'] ?? -1);
			if ($hour >= 0 && $hour <= 23)
			{
				$hours[$hour] = (int)$row['CNT'];
			}
		}

		return $hours;
	}

	/**
	 * @return int[] 24 zeroed entries, indexed 0..23.
	 */
	private function emptyHours(): array
	{
		return array_fill(0, 24, 0);
	}

	/**
	 * Dialect-safe "extract hour of day" SQL expression template for use as an
	 * {@see ExpressionField} (with a single %s placeholder for the DATE_CREATE
	 * column, filled in by the ORM's buildFrom mechanism).
	 *
	 * `HOUR(%s)` is MySQL-specific and does not exist on PostgreSQL, which this
	 * module's box edition also needs to run on; PostgreSQL uses
	 * `EXTRACT(HOUR FROM ...)` instead. Both branches return the hour as an integer
	 * 0..23 in the server's local time zone, same as the previous MySQL-only
	 * behaviour.
	 */
	private function getHourExpression(): string
	{
		$connection = Application::getConnection();

		if ($connection->getType() === 'pgsql')
		{
			return 'EXTRACT(HOUR FROM %s)::int';
		}

		return 'HOUR(%s)';
	}

	/**
	 * @return array<int, array{operatorId:int, count:int, avgWaitAnswer:float, positiveRate:float}>
	 */
	private function loadSessionsByOperator(array $ormFilter): array
	{
		$rows = SessionTable::getList([
			'select' => ['OPERATOR_ID', 'CNT', 'AVG_WAIT_ANSWER', 'LIKE_COUNT', 'DISLIKE_COUNT'],
			'filter' => $ormFilter,
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
				new ExpressionField(
					'AVG_WAIT_ANSWER',
					'AVG(CASE WHEN %s IS NOT NULL AND %s > 0 THEN %s ELSE NULL END)',
					['TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER']
				),
				new ExpressionField('LIKE_COUNT', 'SUM(CASE WHEN %s = ' . Session::VOTE_LIKE . ' THEN 1 ELSE 0 END)', ['VOTE']),
				new ExpressionField('DISLIKE_COUNT', 'SUM(CASE WHEN %s = ' . Session::VOTE_DISLIKE . ' THEN 1 ELSE 0 END)', ['VOTE']),
			],
			'group' => ['OPERATOR_ID'],
			'order' => ['CNT' => 'DESC'],
		])->fetchAll();

		$items = [];
		foreach ($rows as $row)
		{
			$operatorId = (int)($row['OPERATOR_ID'] ?? 0);
			if ($operatorId <= 0)
			{
				continue;
			}

			$like = (int)($row['LIKE_COUNT'] ?? 0);
			$dislike = (int)($row['DISLIKE_COUNT'] ?? 0);
			$voted = $like + $dislike;

			$items[] = [
				'operatorId' => $operatorId,
				'count' => (int)$row['CNT'],
				'avgWaitAnswer' => (float)($row['AVG_WAIT_ANSWER'] ?? 0.0),
				'positiveRate' => $voted > 0 ? $like / $voted : 0.0,
			];
		}

		return $items;
	}

	/**
	 * Parses an incoming REST filter date value (expected to be ISO 8601 / any format
	 * understood by strtotime()) into a Bitrix DateTime. Mirrors
	 * SessionListService::parseDate() (Bitrix's DateTime constructor expects the site
	 * date format, not ISO 8601, so REST-supplied dates must go through strtotime() first).
	 */
	private function parseDate(mixed $value): ?DateTime
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (!is_string($value) && !is_int($value))
		{
			return null;
		}

		$timestamp = is_int($value) ? $value : strtotime($value);
		if ($timestamp === false)
		{
			return null;
		}

		try
		{
			return DateTime::createFromTimestamp($timestamp);
		}
		catch (\Exception)
		{
			return null;
		}
	}
}
