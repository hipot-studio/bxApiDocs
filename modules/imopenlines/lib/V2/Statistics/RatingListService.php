<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\V2\Statistics\Dto\RatingItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Paginated, filterable list of client-voted sessions for a period, for
 * imopenlines.v2.Session.Rating.list.
 *
 * Only sessions with a non-zero client vote (Session::VOTE != 0, i.e. like or
 * dislike) are returned. A line with client voting disabled simply never produces
 * VOTE != 0 rows, so it naturally yields an empty list rather than an error.
 */
final class RatingListService
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;
	private const MAX_PERIOD_DAYS = 366;

	/**
	 * Hard upper bound on the `offset` REST parameter. Even with the mandatory period
	 * and the DATE_CLOSE_VOTE index, a deep offset still forces the DB to read and
	 * discard every preceding row (OFFSET n with no seek), so cap it like Session.list
	 * does - suggestion perf item, see review round 6 #3.
	 */
	private const MAX_OFFSET = 10000;

	public const ERROR_INVALID_FILTER = 'INVALID_FILTER';
	public const ERROR_PERIOD_REQUIRED = 'PERIOD_REQUIRED';
	public const ERROR_PERIOD_TOO_LARGE = 'PERIOD_TOO_LARGE';
	public const ERROR_OFFSET_TOO_LARGE = 'OFFSET_TOO_LARGE';

	public function getList(int $userId, array $filter, int $offset, int $limit): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if (
			empty($filter['dateVoteFrom'])
			|| empty($filter['dateVoteTo'])
		)
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_RATING_LIST_PERIOD_REQUIRED'), self::ERROR_PERIOD_REQUIRED));

			return $result;
		}

		if ($offset > self::MAX_OFFSET)
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_RATING_LIST_OFFSET_TOO_LARGE'), self::ERROR_OFFSET_TOO_LARGE));

			return $result;
		}

		$ormFilter = $this->buildOrmFilter($userId, $filter, $result);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if ($ormFilter === null)
		{
			// No visibility at all: empty list, not an error.
			$result->setData([
				'ratings' => [],
				'hasNextPage' => false,
			]);

			return $result;
		}

		$limit = $this->normalizeLimit($limit);
		$offset = max(0, $offset);

		$rows = SessionTable::getList([
			'select' => [
				'ID',
				'CONFIG_ID',
				'OPERATOR_ID',
				'SOURCE',
				'VOTE',
				'VOTE_HEAD',
				'COMMENT_HEAD',
				'DATE_CLOSE_VOTE',
				'DATE_CLOSE',
			],
			'filter' => $ormFilter,
			'order' => ['DATE_CLOSE_VOTE' => 'DESC', 'ID' => 'DESC'],
			'limit' => $limit + 1,
			'offset' => $offset,
		])->fetchAll();

		$hasNextPage = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		// Precompute per-line vote-head visibility for the whole page in one batch instead
		// of resolving Config::canVoteAsHead() per distinct line inside the masking loop.
		$voteHeadCache = AccessGate::buildVoteHeadVisibilityCache(array_column($rows, 'CONFIG_ID'), $userId);

		$items = [];
		foreach ($rows as $row)
		{
			$item = (new RatingItem($row))->toRestFormat();
			$item = AccessGate::maskVoteHeadFieldsForConfig($item, (int)$row['CONFIG_ID'], $userId, $voteHeadCache);
			$items[] = $item;
		}

		$result->setData([
			'ratings' => $items,
			'hasNextPage' => $hasNextPage,
		]);

		return $result;
	}

	/**
	 * @return array|null The ORM filter, or null when the current user has no
	 *                     visibility at all (empty allowed-operators set).
	 */
	private function buildOrmFilter(int $userId, array $filter, Result $result): ?array
	{
		$ormFilter = [
			// Exact like/dislike values instead of a "!=" predicate: matches the
			// (CONFIG_ID, DATE_CLOSE_VOTE) index better than a not-equal scan and is
			// equivalent since Session::VOTE is only ever 0, VOTE_LIKE or VOTE_DISLIKE.
			'=VOTE' => [Session::VOTE_LIKE, Session::VOTE_DISLIKE],
		];

		$filter = $this->normalizeListFilters($filter, $result);
		if (!$result->isSuccess())
		{
			return null;
		}

		$this->applyInFilter($ormFilter, '=CONFIG_ID', $filter['configId'] ?? null);
		$this->applyInFilter($ormFilter, '=SOURCE', $filter['source'] ?? null);

		if (array_key_exists('operatorId', $filter))
		{
			$this->applyInFilter($ormFilter, '=OPERATOR_ID', $filter['operatorId']);
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

		if (isset($filter['vote']))
		{
			$this->applyVoteFilter($ormFilter, (string)$filter['vote'], $result);
			if (!$result->isSuccess())
			{
				return null;
			}
		}

		if (array_key_exists('hasVoteHead', $filter) && AccessGate::canUseVoteHead())
		{
			$voteHeadScope = AccessGate::resolveVoteHeadFilterScope($filter['configId'] ?? null, $userId);

			if ($voteHeadScope['apply'])
			{
				if ($voteHeadScope['configIds'] !== null)
				{
					// Narrow CONFIG_ID to lines the caller actually has the
					// vote-head permission on, so disallowed lines' sessions are
					// excluded from the result outright instead of their presence
					// depending on the (masked) VOTE_HEAD value - see AccessGate::
					// resolveVoteHeadFilterScope().
					$ormFilter['=CONFIG_ID'] = $voteHeadScope['configIds'];
				}

				if ($filter['hasVoteHead'])
				{
					$ormFilter['>VOTE_HEAD'] = 0;
				}
				else
				{
					// An un-rated session may store VOTE_HEAD as NULL (DDL nullable) or 0, and
					// RatingItem serializes both as voteHead: null, so hasVoteHead=false must match
					// both. `=VOTE_HEAD => 0` already covers NULL (the ORM field is required/default
					// 0, so the operator expands to `IS NULL OR = 0`), but we spell the NULL branch
					// out explicitly so the inclusion does not silently depend on that
					// default-value behaviour.
					$ormFilter[] = [
						'LOGIC' => 'OR',
						['=VOTE_HEAD' => null],
						['=VOTE_HEAD' => 0],
					];
				}
			}
			// else: no line in scope has the permission - drop the predicate so the
			// result composition/hasNextPage no longer depends on the hidden field.
		}

		$this->applyDateRange($ormFilter, $filter, 'dateVoteFrom', 'dateVoteTo', 'DATE_CLOSE_VOTE', $result);
		if (!$result->isSuccess())
		{
			return null;
		}

		return $ormFilter;
	}

	private function applyInFilter(array &$ormFilter, string $key, mixed $value): void
	{
		if ($value === null)
		{
			return;
		}

		$ormFilter[$key] = is_array($value) ? array_values($value) : $value;
	}

	/**
	 * Normalizes caller-supplied list filters (configId/operatorId to positive ints,
	 * source to non-empty strings), dedupes them and rejects any list exceeding
	 * {@see FilterNormalizer::MAX_LIST_SIZE}. A provided list that normalizes to empty
	 * (e.g. [0] or a non-numeric id) is reported as INVALID_FILTER, so an explicit filter
	 * never silently broadens the query to all rows.
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
					Loc::getMessage('IMOL_STAT_RATING_LIST_FILTER_LIST_TOO_LARGE', ['#FIELD#' => $key]),
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
					Loc::getMessage('IMOL_STAT_RATING_LIST_EMPTY_FILTER_LIST', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			$filter[$key] = $values;
		}

		return $filter;
	}

	private function applyVoteFilter(array &$ormFilter, string $vote, Result $result): void
	{
		switch ($vote)
		{
			case Dictionary::VOTE_LIKE:
				$ormFilter['=VOTE'] = Session::VOTE_LIKE;
				break;

			case Dictionary::VOTE_DISLIKE:
				$ormFilter['=VOTE'] = Session::VOTE_DISLIKE;
				break;

			default:
				$result->addError(new Error(Loc::getMessage('IMOL_STAT_RATING_LIST_INVALID_VOTE_FILTER'), self::ERROR_INVALID_FILTER));
		}
	}

	private function applyDateRange(
		array &$ormFilter,
		array $filter,
		string $fromKey,
		string $toKey,
		string $ormField,
		Result $result
	): void
	{
		$from = $this->parseDate($filter[$fromKey] ?? null);
		$to = $this->parseDate($filter[$toKey] ?? null);

		if (($filter[$fromKey] ?? null) !== null && $from === null)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_RATING_LIST_INVALID_DATE_VALUE', ['#FIELD#' => $fromKey]),
				self::ERROR_INVALID_FILTER
			));

			return;
		}

		if (($filter[$toKey] ?? null) !== null && $to === null)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_RATING_LIST_INVALID_DATE_VALUE', ['#FIELD#' => $toKey]),
				self::ERROR_INVALID_FILTER
			));

			return;
		}

		if ($from !== null && $to !== null)
		{
			// Calendar-day diff() avoids a false PERIOD_TOO_LARGE on a DST transition, where
			// an allowed 366-calendar-day span is 366 days + 1h in raw seconds.
			$interval = $from->getDiff($to);
			if ($interval->invert === 1)
			{
				// from is later than to: the >= / <= predicates can never both hold, so this
				// would silently return an empty list. Reject the inverted range as a bad filter.
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_RATING_LIST_INVALID_PERIOD_ORDER', ['#FROM#' => $fromKey, '#TO#' => $toKey]),
					self::ERROR_INVALID_FILTER
				));

				return;
			}

			if ($interval->days > self::MAX_PERIOD_DAYS)
			{
				$result->addError(new Error(Loc::getMessage('IMOL_STAT_PERIOD_TOO_LARGE'), self::ERROR_PERIOD_TOO_LARGE));

				return;
			}
		}

		if ($from !== null)
		{
			$ormFilter[">={$ormField}"] = $from;
		}

		if ($to !== null)
		{
			$ormFilter["<={$ormField}"] = $to;
		}
	}

	/**
	 * Parses an incoming REST filter date value (expected to be ISO 8601 / any format
	 * understood by strtotime()) into a Bitrix DateTime. Mirrors
	 * SessionListService::parseDate().
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

	private function normalizeLimit(int $limit): int
	{
		return $limit > 0 && $limit <= self::MAX_LIMIT ? $limit : self::DEFAULT_LIMIT;
	}
}
