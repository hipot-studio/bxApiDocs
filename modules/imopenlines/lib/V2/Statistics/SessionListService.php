<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenLines\Model\OperatorTransferTable;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\V2\Statistics\Dto\SessionListItem;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Paginated, filterable list of imopenlines sessions with reporting metrics for
 * imopenlines.v2.Session.list.
 */
final class SessionListService
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;
	private const MAX_PERIOD_DAYS = 366;

	/**
	 * Hard upper bound on the `offset` REST parameter. Session.list has no period
	 * filter requirement (unlike Rating.list / Stat.get), so without this cap a caller
	 * could page arbitrarily deep into an unbounded, indexed-but-still-large result
	 * set (OFFSET n with no way to seek) - suggestion perf item, see review round 5 #6.
	 */
	private const MAX_OFFSET = 10000;

	public const ERROR_PERIOD_TOO_LARGE = 'PERIOD_TOO_LARGE';
	public const ERROR_INVALID_FILTER = 'INVALID_FILTER';
	public const ERROR_OFFSET_TOO_LARGE = 'OFFSET_TOO_LARGE';

	private const ORDERABLE_FIELDS = [
		'dateCreate' => 'DATE_CREATE',
		'dateClose' => 'DATE_CLOSE',
		'waitAnswer' => 'TIME_FIRST_ANSWER',
		'waitClose' => 'TIME_CLOSE',
	];

	public function getList(int $userId, array $filter, string $order, string $orderDirection, int $offset, int $limit): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if ($offset > self::MAX_OFFSET)
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_LIST_OFFSET_TOO_LARGE'), self::ERROR_OFFSET_TOO_LARGE));

			return $result;
		}

		$ormFilter = $this->buildOrmFilter($userId, $filter, $result);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$limit = $this->normalizeLimit($limit);
		$offset = max(0, $offset);
		[$orderField, $orderDirection] = $this->normalizeOrder($order, $orderDirection, $filter);

		$rows = SessionTable::getList([
			'select' => [
				'ID',
				'CONFIG_ID',
				'SOURCE',
				'OPERATOR_ID',
				'USER_ID',
				'USER_CODE',
				'CHAT_ID',
				'DATE_CREATE',
				'DATE_CLOSE',
				'DATE_FIRST_ANSWER',
				'DATE_OPERATOR_ANSWER',
				'DATE_OPERATOR_CLOSE',
				'STATUS',
				'CLOSED',
				'SPAM',
				'PAUSE',
				'BLOCK_REASON',
				'BLOCK_DATE',
				'VOTE',
				'VOTE_HEAD',
				'COMMENT_HEAD',
				'CRM',
				'CRM_ACTIVITY_ID',
				'TIME_FIRST_ANSWER',
				'TIME_CLOSE',
				'MESSAGE_COUNT',
				'KPI_FIRST_ANSWER_TIME' => 'CONFIG.KPI_FIRST_ANSWER_TIME',
			],
			'filter' => $ormFilter,
			'runtime' => [
				// Enables the kpiFirstAnswer filter: 1 when the line has a KPI configured and
				// the first answer met it, 0 when it was configured but missed, NULL when the
				// KPI is not applicable (no KPI on the line, or the session was never answered
				// so TIME_FIRST_ANSWER is 0). The NULL branch is essential: without it these
				// non-applicable rows would collapse to 0 and leak into the kpiFirstAnswer=false
				// result set, contradicting enrichWithKpiFirstAnswer() which serializes them as
				// null. Keep the two conditions in lockstep with that method.
				new ExpressionField(
					'KPI_FIRST_ANSWER_MET',
					'CASE WHEN %s > 0 AND %s > 0 THEN CASE WHEN %s <= %s THEN 1 ELSE 0 END ELSE NULL END',
					['CONFIG.KPI_FIRST_ANSWER_TIME', 'TIME_FIRST_ANSWER', 'TIME_FIRST_ANSWER', 'CONFIG.KPI_FIRST_ANSWER_TIME']
				),
			],
			// ID as a tiebreaker keeps offset pagination deterministic: the sort fields
			// (DATE_CREATE/DATE_CLOSE/TIME_*) are non-unique, and without a stable
			// secondary key the DB may order equal-valued rows differently between page
			// requests, causing skipped or duplicated rows across offsets (same approach
			// as RatingListService).
			'order' => [$orderField => $orderDirection, 'ID' => $orderDirection],
			'limit' => $limit + 1,
			'offset' => $offset,
		])->fetchAll();

		$hasNextPage = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		$this->enrichWithQueueTransfers($rows);
		$this->enrichWithCrmEntity($rows, $userId);
		$this->enrichWithKpiFirstAnswer($rows);

		// Precompute per-line vote-head visibility for the whole page in one batch instead
		// of resolving Config::canVoteAsHead() per distinct line inside the masking loop.
		$voteHeadCache = AccessGate::buildVoteHeadVisibilityCache(array_column($rows, 'CONFIG_ID'), $userId);

		$items = [];
		foreach ($rows as $row)
		{
			$item = (new SessionListItem($row))->toRestFormat();
			$item = AccessGate::maskVoteHeadFieldsForConfig($item, (int)$row['CONFIG_ID'], $userId, $voteHeadCache);
			$items[] = $item;
		}

		$result->setData([
			'sessions' => $items,
			'hasNextPage' => $hasNextPage,
		]);

		return $result;
	}

	private function buildOrmFilter(int $userId, array $filter, Result $result): array
	{
		$ormFilter = [];

		$filter = $this->normalizeListFilters($filter, $result);
		if (!$result->isSuccess())
		{
			return $ormFilter;
		}

		$this->applyInFilter($ormFilter, '=CONFIG_ID', $filter['configId'] ?? null);
		$this->applyInFilter($ormFilter, '=SOURCE', $filter['source'] ?? null);

		if (array_key_exists('operatorId', $filter))
		{
			$this->applyInFilter($ormFilter, '=OPERATOR_ID', $filter['operatorId']);
		}

		$allowedUserIds = AllowedOperators::getAllowedOperatorIds($userId);
		if (is_array($allowedUserIds))
		{
			if (empty($allowedUserIds))
			{
				// No visibility at all: force an impossible filter instead of leaking rows.
				$ormFilter['=OPERATOR_ID'] = -1;
			}
			else
			{
				$existing = $ormFilter['=OPERATOR_ID'] ?? null;
				$operatorIds = $existing === null
					? $allowedUserIds
					: array_values(array_intersect((array)$existing, $allowedUserIds));
				// A requested operatorId outside the allowed set yields an empty intersection;
				// force the same impossible filter as the empty allow-set branch instead of
				// relying on the ORM's empty-IN fail-closed semantics.
				$ormFilter['=OPERATOR_ID'] = empty($operatorIds) ? -1 : $operatorIds;
			}
		}

		if (isset($filter['status']) && isset($filter['closeReason']))
		{
			// status and closeReason are overlapping lifecycle dimensions that both constrain
			// the same underlying columns (=CLOSED/=SPAM/=STATUS). Applying both would let the
			// second silently overwrite the first (e.g. status=new + closeReason=operator would
			// end up returning closed sessions). closeReason already implies a closed session,
			// so the two are mutually exclusive rather than combinable.
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_LIST_STATUS_CLOSE_REASON_CONFLICT'), self::ERROR_INVALID_FILTER));
		}
		elseif (isset($filter['status']))
		{
			$this->applyStatusFilter($ormFilter, (string)$filter['status'], $result);
		}
		elseif (isset($filter['closeReason']))
		{
			$this->applyCloseReasonFilter($ormFilter, (string)$filter['closeReason'], $result);
		}

		if (isset($filter['vote']))
		{
			$this->applyVoteFilter($ormFilter, (string)$filter['vote'], $result);
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
					// SessionListItem serializes both as voteHead: null, so hasVoteHead=false must
					// match both. `=VOTE_HEAD => 0` already covers NULL (the ORM field is
					// required/default 0, so the operator expands to `IS NULL OR = 0`), but we
					// spell the NULL branch out explicitly so the inclusion does not silently
					// depend on that default-value behaviour.
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

		if (array_key_exists('hasCrm', $filter) && $this->canProbeCrmBinding($userId))
		{
			// hasCrm filters on the presence of a *resolvable* CRM binding. It is applied at
			// the DB level before the ACL-aware CRM entity resolution (see enrichWithCrmEntity()),
			// so honouring it for a caller who cannot read the bound CRM entities would let
			// them infer which sessions carry a binding via the result composition /
			// hasNextPage - a side-channel oracle - even though crmEntityType/crmEntityId are
			// masked out of the payload. Only apply the predicate for callers with CRM read
			// access; otherwise drop it so the result no longer depends on the hidden fact.
			//
			// The predicate must match the exact condition under which enrichWithCrmEntity()
			// resolves a binding (CRM='Y' AND CRM_ACTIVITY_ID>0); otherwise hasCrm=true could
			// return a session whose crmEntityType/crmEntityId serialize as null (CRM='Y' but
			// no activity), and hasCrm=false would drop it - inconsistent with the payload.
			if ($filter['hasCrm'])
			{
				$ormFilter['=CRM'] = 'Y';
				$ormFilter['>CRM_ACTIVITY_ID'] = 0;
			}
			else
			{
				$ormFilter[] = [
					'LOGIC' => 'OR',
					['!=CRM' => 'Y'],
					['=CRM_ACTIVITY_ID' => null],
					['<=CRM_ACTIVITY_ID' => 0],
				];
			}
		}

		if (array_key_exists('kpiFirstAnswer', $filter))
		{
			// The kpiFirstAnswer predicate filters on the KPI_FIRST_ANSWER_MET runtime
			// expression - a non-indexable CASE joined to CONFIG that the DB must evaluate for
			// every candidate row. Session.list has no mandatory period, so without a bounded
			// window this would force a full scan of b_imopenlines_session before limit/offset
			// apply. Require a complete date window (dateCreate* or dateClose*, capped at
			// MAX_PERIOD_DAYS by applyDateRange) so the scan stays bounded by an indexed range.
			if ($this->hasBoundedPeriod($filter))
			{
				$ormFilter['=KPI_FIRST_ANSWER_MET'] = $filter['kpiFirstAnswer'] ? 1 : 0;
			}
			else
			{
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_SESSION_LIST_KPI_PERIOD_REQUIRED'),
					self::ERROR_INVALID_FILTER
				));
			}
		}

		if (isset($filter['waitAnswerFrom']))
		{
			$ormFilter['>=TIME_FIRST_ANSWER'] = (int)$filter['waitAnswerFrom'];
		}

		if (isset($filter['waitAnswerTo']))
		{
			$ormFilter['<=TIME_FIRST_ANSWER'] = (int)$filter['waitAnswerTo'];
		}

		if (isset($filter['waitCloseFrom']))
		{
			$ormFilter['>=TIME_CLOSE'] = (int)$filter['waitCloseFrom'];
		}

		if (isset($filter['waitCloseTo']))
		{
			$ormFilter['<=TIME_CLOSE'] = (int)$filter['waitCloseTo'];
		}

		$this->applyDateRange($ormFilter, $filter, 'dateCreateFrom', 'dateCreateTo', 'DATE_CREATE', $result, checkPeriod: true);
		$this->applyDateRange($ormFilter, $filter, 'dateCloseFrom', 'dateCloseTo', 'DATE_CLOSE', $result, checkPeriod: true);

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
	 * {@see FilterNormalizer::MAX_LIST_SIZE} - Session.list runs against the hot session
	 * table, so an unbounded `IN (...)` here is especially costly. A provided list that
	 * normalizes to empty (e.g. [0] or a non-numeric id) is reported as INVALID_FILTER, so
	 * an explicit filter never silently broadens the query to all rows.
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
					Loc::getMessage('IMOL_STAT_SESSION_LIST_FILTER_LIST_TOO_LARGE', ['#FIELD#' => $key]),
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
					Loc::getMessage('IMOL_STAT_SESSION_LIST_EMPTY_FILTER_LIST', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			$filter[$key] = $values;
		}

		return $filter;
	}

	private function applyStatusFilter(array &$ormFilter, string $status, Result $result): void
	{
		switch ($status)
		{
			case Dictionary::SESSION_STATUS_NEW:
				$ormFilter['=STATUS'] = [Session::STATUS_NEW, Session::STATUS_SKIP];
				$ormFilter['=CLOSED'] = 'N';
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['=PAUSE'] = 'N';
				break;

			case Dictionary::SESSION_STATUS_ANSWERED:
				$ormFilter['>=STATUS'] = Session::STATUS_ANSWER;
				$ormFilter['<=STATUS'] = Session::STATUS_WAIT_CLIENT;
				$ormFilter['=CLOSED'] = 'N';
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['=PAUSE'] = 'N';
				break;

			case Dictionary::SESSION_STATUS_CLOSED:
				// Match Dictionary::getSessionStatus(), which serializes a session as `closed`
				// not only when CLOSED='Y' but also for the closing STATUS values even if
				// CLOSED is still 'N' (a session finished by status but not yet flagged). Spam
				// still wins over closed there, so SPAM='N' is kept.
				$ormFilter['=SPAM'] = 'N';
				$ormFilter[] = [
					'LOGIC' => 'OR',
					['=CLOSED' => 'Y'],
					['=STATUS' => [Session::STATUS_CLOSE, Session::STATUS_SILENTLY_CLOSE, Session::STATUS_DUPLICATE]],
				];
				break;

			case Dictionary::SESSION_STATUS_SPAM:
				$ormFilter['=SPAM'] = 'Y';
				break;

			case Dictionary::SESSION_STATUS_PAUSED:
				$ormFilter['=PAUSE'] = 'Y';
				$ormFilter['=CLOSED'] = 'N';
				$ormFilter['=SPAM'] = 'N';
				// Dictionary::getSessionStatus() serializes a closing STATUS as `closed` BEFORE
				// it checks PAUSE, so a row with a closing STATUS and PAUSE='Y' would serialize
				// as `closed` yet still match this filter. Exclude those statuses to keep the
				// filter consistent with the serialized value (same statuses as the closed branch).
				$ormFilter['!=STATUS'] = [Session::STATUS_CLOSE, Session::STATUS_SILENTLY_CLOSE, Session::STATUS_DUPLICATE];
				break;

			default:
				$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_STATUS_FILTER'), self::ERROR_INVALID_FILTER));
		}
	}

	/**
	 * Translates the derived closeReason enum (see Dictionary::getCloseReason()) into
	 * ORM predicates over the underlying columns, so it can be applied at the DB level
	 * instead of breaking pagination with a post-fetch filter.
	 *
	 * This is a best-effort approximation of Dictionary::getCloseReason()'s priority
	 * chain: it cannot see the IS_CLOSED_BY_OPERATOR flag (that flag is not a persisted
	 * column - see Dictionary.php), so `operator` and `client` are approximated via
	 * DATE_OPERATOR_CLOSE IS NOT NULL, the same proxy used by getCloseReason() callers
	 * in this module (see class doc / concerns).
	 */
	private function applyCloseReasonFilter(array &$ormFilter, string $closeReason, Result $result): void
	{
		$ormFilter['=CLOSED'] = 'Y';

		switch ($closeReason)
		{
			case Dictionary::CLOSE_REASON_SPAM:
				$ormFilter['=SPAM'] = 'Y';
				break;

			case Dictionary::CLOSE_REASON_REPLY_LIMIT:
				// Dictionary additionally requires BLOCK_DATE <= DATE_CLOSE, which is a
				// column-to-column comparison the ORM filter syntax cannot express here;
				// this predicate is therefore a superset (BLOCK_REASON set, not closed by
				// an operator) - see Concerns in the phase 2 report.
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['!=BLOCK_REASON'] = '';
				$ormFilter['=DATE_OPERATOR_CLOSE'] = null;
				break;

			case Dictionary::CLOSE_REASON_AUTO:
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['=STATUS'] = Session::STATUS_SILENTLY_CLOSE;
				// Exclude sessions with a BLOCK_REASON set: Dictionary::getCloseReason() checks
				// replyLimit (BLOCK_REASON) before auto, so a silently-closed session carrying a
				// BLOCK_REASON serializes as `replyLimit`, not `auto`. Mirror the `client` branch
				// below so the filter never returns rows that serialize with a different
				// closeReason. Trade-off is the same: this also drops the rarer legitimate `auto`
				// rows where a block was set but had already expired before close (the ORM cannot
				// express the BLOCK_DATE <= DATE_CLOSE column-to-column comparison here).
				$ormFilter[] = [
					'LOGIC' => 'OR',
					['=BLOCK_REASON' => null],
					['=BLOCK_REASON' => ''],
				];
				break;

			case Dictionary::CLOSE_REASON_OPERATOR:
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['!=DATE_OPERATOR_CLOSE'] = null;
				// Exclude auto-closed (silently closed) sessions: Dictionary::getCloseReason()
				// gives CLOSE_REASON_AUTO a higher priority than CLOSE_REASON_OPERATOR, so a
				// STATUS_SILENTLY_CLOSE session that also has DATE_OPERATOR_CLOSE set would pass
				// this predicate yet serialize as `auto`. Keep the filter consistent with the
				// serialized value, mirroring the `client` branch below.
				$ormFilter['!=STATUS'] = Session::STATUS_SILENTLY_CLOSE;
				break;

			case Dictionary::CLOSE_REASON_CLIENT:
				$ormFilter['=SPAM'] = 'N';
				$ormFilter['=DATE_OPERATOR_CLOSE'] = null;
				$ormFilter['!=STATUS'] = Session::STATUS_SILENTLY_CLOSE;
				// Also exclude any session with a BLOCK_REASON set. Without this, a session
				// closed not-by-operator with a BLOCK_REASON whose BLOCK_DATE <= DATE_CLOSE
				// would pass this filter while Dictionary::getCloseReason() actually
				// serializes it as CLOSE_REASON_REPLY_LIMIT (see that method's priority
				// order) - the ORM cannot express the BLOCK_DATE <= DATE_CLOSE column-to-
				// column comparison to filter on that condition precisely. Trade-off:
				// this also drops the rarer legitimate "client" rows where a block was set
				// but had already expired before the session closed; accepted so the filter
				// never returns rows that serialize with a different closeReason.
				$ormFilter[] = [
					'LOGIC' => 'OR',
					['=BLOCK_REASON' => null],
					['=BLOCK_REASON' => ''],
				];
				break;

			default:
				$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_CLOSE_REASON_FILTER'), self::ERROR_INVALID_FILTER));
		}
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

			case Dictionary::VOTE_NONE:
				$ormFilter['=VOTE'] = 0;
				break;

			case 'any':
				// no predicate
				break;

			default:
				$result->addError(new Error(Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_VOTE_FILTER'), self::ERROR_INVALID_FILTER));
		}
	}

	private function applyDateRange(
		array &$ormFilter,
		array $filter,
		string $fromKey,
		string $toKey,
		string $ormField,
		Result $result,
		bool $checkPeriod
	): void
	{
		$from = $this->parseDate($filter[$fromKey] ?? null);
		$to = $this->parseDate($filter[$toKey] ?? null);

		if (($filter[$fromKey] ?? null) !== null && $from === null)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_DATE_VALUE', ['#FIELD#' => $fromKey]),
				self::ERROR_INVALID_FILTER
			));

			return;
		}

		if (($filter[$toKey] ?? null) !== null && $to === null)
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_DATE_VALUE', ['#FIELD#' => $toKey]),
				self::ERROR_INVALID_FILTER
			));

			return;
		}

		if ($from !== null && $to !== null)
		{
			// Compare calendar days via diff(), not (timestamp diff)/86400: across a DST
			// transition a genuinely-allowed 366-calendar-day span is 366 days + 1h in
			// seconds and would be falsely rejected as PERIOD_TOO_LARGE.
			$interval = $from->getDiff($to);
			if ($interval->invert === 1)
			{
				// from is later than to: the >= / <= predicates can never both hold, so this
				// would silently return an empty list. Reject the inverted range as a bad filter.
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_SESSION_LIST_INVALID_PERIOD_ORDER', ['#FROM#' => $fromKey, '#TO#' => $toKey]),
					self::ERROR_INVALID_FILTER
				));

				return;
			}

			if ($checkPeriod && $interval->days > self::MAX_PERIOD_DAYS)
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
	 * understood by strtotime()) into a Bitrix DateTime.
	 *
	 * Bitrix's `new DateTime($value)` constructor expects the site date format (e.g.
	 * `d.m.Y`), not ISO 8601, so REST-supplied dates must go through strtotime() first.
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

	/**
	 * Resolves the requested order into an ORM field + direction.
	 *
	 * `waitAnswer`/`waitClose`/`dateClose` sort on TIME_FIRST_ANSWER/TIME_CLOSE/
	 * DATE_CLOSE, none of which are indexed the way DATE_CREATE is. Sorting by them
	 * with a deep offset and no period filter would force a filesort over the whole
	 * (unbounded) result set, so they are only honored when the caller also supplies
	 * a full dateCreate or dateClose range; otherwise this falls back to the indexed
	 * `dateCreate` order rather than erroring, per the phase 4 review decision
	 * (option a). `dateCreate` itself remains always honored, period or not.
	 */
	private function normalizeOrder(string $order, string $direction, array $filter): array
	{
		$ormField = self::ORDERABLE_FIELDS[$order] ?? 'DATE_CREATE';
		$direction = mb_strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

		if (in_array($order, ['waitAnswer', 'waitClose', 'dateClose'], true) && !$this->hasBoundedPeriod($filter))
		{
			$ormField = self::ORDERABLE_FIELDS['dateCreate'];
		}

		return [$ormField, $direction];
	}

	/**
	 * Whether the incoming REST filter restricts the result set to a bounded
	 * dateCreate or dateClose range (both endpoints supplied).
	 */
	private function hasBoundedPeriod(array $filter): bool
	{
		$hasCreateRange = !empty($filter['dateCreateFrom']) && !empty($filter['dateCreateTo']);
		$hasCloseRange = !empty($filter['dateCloseFrom']) && !empty($filter['dateCloseTo']);

		return $hasCreateRange || $hasCloseRange;
	}

	private function enrichWithQueueTransfers(array &$rows): void
	{
		if (empty($rows))
		{
			return;
		}

		$sessionIds = array_map(static fn(array $row) => (int)$row['ID'], $rows);

		$counts = OperatorTransferTable::getList([
			'select' => ['SESSION_ID', 'CNT'],
			'filter' => ['=SESSION_ID' => $sessionIds],
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['SESSION_ID'],
		])->fetchAll();

		$countsBySessionId = [];
		foreach ($counts as $count)
		{
			$countsBySessionId[(int)$count['SESSION_ID']] = (int)$count['CNT'];
		}

		foreach ($rows as &$row)
		{
			$row['QUEUE_TRANSFERS'] = $countsBySessionId[(int)$row['ID']] ?? 0;
		}
		unset($row);
	}

	/**
	 * Resolves crmEntityType/crmEntityId for all rows with a single batch query over
	 * the CRM activity-binding table, instead of one Crm\Common::getActivityBindings()
	 * call (and therefore one SELECT) per row - there is no batch method for this in
	 * the crm module's public API, so this reads b_crm_act_bind directly via
	 * \CCrmActivity::BINDING_TABLE_NAME, mirroring exactly what
	 * \CAllCrmActivity::GetBindings() does per-activity (see crm module,
	 * classes/general/crm_activity.php), but with one `ACTIVITY_ID IN (...)` query for
	 * the whole page. Only for rows with CRM='Y' and a positive CRM_ACTIVITY_ID.
	 *
	 * A resolved entity is only attached to the row if $userId has CRM read access to
	 * it ({@see self::filterReadableCrmEntities()}) - otherwise crmEntityType/
	 * crmEntityId are left unset (SessionListItem then defaults both to null). Without
	 * this, a user with imopenlines session visibility but no CRM read right on the
	 * bound deal/lead/contact/company could still learn its type, ID, and the fact
	 * that a binding exists at all - security review round 5 concern #5.
	 */
	/**
	 * Whether $userId is allowed to use the hasCrm filter without leaking the existence
	 * of a CRM binding they cannot otherwise see. True only when the crm module is
	 * available and the caller can read *every* item of *every* CRM entity type an OL
	 * session can be bound to (deal/lead/contact/company). A type-level "can read some"
	 * check is not enough: a caller with restricted read (own/department only) could
	 * still compare hasCrm=true/false result composition / hasNextPage to learn that a
	 * visible session carries a binding whose entity is then masked out of the payload.
	 * When the caller lacks unrestricted read on any bound type the predicate is dropped
	 * by buildOrmFilter() so result composition no longer depends on hidden CRM bindings.
	 */
	private function canProbeCrmBinding(int $userId): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$userPermissions = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($userId);

		$boundTypes = [
			\CCrmOwnerType::Deal,
			\CCrmOwnerType::Lead,
			\CCrmOwnerType::Contact,
			\CCrmOwnerType::Company,
		];

		foreach ($boundTypes as $entityTypeId)
		{
			if (!$userPermissions->entityType()->canReadAllItemsOfType($entityTypeId))
			{
				return false;
			}
		}

		return true;
	}

	private function enrichWithCrmEntity(array &$rows, int $userId): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$activityIds = [];
		foreach ($rows as $row)
		{
			$activityId = (int)($row['CRM_ACTIVITY_ID'] ?? 0);
			if (($row['CRM'] ?? 'N') === 'Y' && $activityId > 0)
			{
				$activityIds[$activityId] = true;
			}
		}

		if (empty($activityIds))
		{
			return;
		}

		$entityByActivityId = $this->loadCrmEntitiesByActivityIds(array_keys($activityIds));

		if (empty($entityByActivityId))
		{
			return;
		}

		$readableEntities = $this->filterReadableCrmEntities($entityByActivityId, $userId);

		foreach ($rows as &$row)
		{
			$activityId = (int)($row['CRM_ACTIVITY_ID'] ?? 0);
			$entity = $entityByActivityId[$activityId] ?? null;
			if ($entity === null)
			{
				continue;
			}

			if (!isset($readableEntities[$entity['TYPE']][$entity['ID']]))
			{
				continue;
			}

			$row['CRM_ENTITY_TYPE'] = $entity['TYPE'];
			$row['CRM_ENTITY_ID'] = $entity['ID'];
		}
		unset($row);
	}

	/**
	 * Filters resolved CRM entities down to the ones $userId has read access to, via
	 * the modern permission API ({@see \Bitrix\Crm\Service\UserPermissions::item()}),
	 * the non-deprecated equivalent of legacy \CCrmPerms::CheckEntity().
	 *
	 * Batched so the query count is bounded by the number of distinct CRM entity types
	 * present on the page (at most 4: deal/lead/contact/company), not by the number of
	 * rows: {@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Item::preloadPermissionAttributes()}
	 * primes the permission-attribute cache with one query per type for every id of
	 * that type, so the per-id canRead() calls that follow are cache hits.
	 *
	 * @param array<int, array{TYPE: string, ID: int}> $entityByActivityId
	 * @return array<string, array<int, bool>> Readable entity ids keyed by TYPE (same
	 *                                          lowercase string as $entityByActivityId),
	 *                                          then by ID.
	 */
	private function filterReadableCrmEntities(array $entityByActivityId, int $userId): array
	{
		$idsByType = [];
		foreach ($entityByActivityId as $entity)
		{
			$idsByType[$entity['TYPE']][(int)$entity['ID']] = true;
		}

		$userPermissions = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions($userId);

		$readable = [];
		foreach ($idsByType as $type => $idsMap)
		{
			$entityTypeId = \CCrmOwnerType::ResolveID((string)$type);
			if ($entityTypeId <= 0)
			{
				continue;
			}

			$ids = array_keys($idsMap);

			$userPermissions->item()->preloadPermissionAttributes($entityTypeId, $ids);

			foreach ($ids as $id)
			{
				if ($userPermissions->item()->canRead($entityTypeId, $id))
				{
					$readable[$type][$id] = true;
				}
			}
		}

		return $readable;
	}

	/**
	 * @param int[] $activityIds
	 * @return array<int, array{TYPE: string, ID: int}> Resolved entity keyed by
	 *                                                    CRM_ACTIVITY_ID, same
	 *                                                    DEAL>LEAD>CONTACT>COMPANY
	 *                                                    priority as
	 *                                                    Crm\Common::getActivityBindings().
	 */
	private function loadCrmEntitiesByActivityIds(array $activityIds): array
	{
		// Priority order when an activity is bound to several CRM entities simultaneously,
		// same order as Crm\Common::getActivityBindings()'s $priority.
		$priority = ['DEAL', 'LEAD', 'CONTACT', 'COMPANY'];

		$connection = Application::getConnection();

		$idList = implode(',', array_map('intval', $activityIds));

		$sql = "
			SELECT ACTIVITY_ID, OWNER_TYPE_ID, OWNER_ID
			FROM " . \CCrmActivity::BINDING_TABLE_NAME . "
			WHERE ACTIVITY_ID IN ({$idList})
		";

		$dbResult = $connection->query($sql);

		// Per activity, keep the lowest OWNER_ID per type, same tie-break as
		// \CAllCrmActivity::GetBindings() callers use (see getActivityBindings()).
		$bindingsByActivityId = [];
		while ($bindingRow = $dbResult->fetch())
		{
			$activityId = (int)$bindingRow['ACTIVITY_ID'];
			$type = \CCrmOwnerType::ResolveName((int)$bindingRow['OWNER_TYPE_ID']);

			if (!in_array($type, $priority, true))
			{
				continue;
			}

			$ownerId = (int)$bindingRow['OWNER_ID'];
			$current = $bindingsByActivityId[$activityId][$type] ?? 0;

			if ($current === 0 || $current > $ownerId)
			{
				$bindingsByActivityId[$activityId][$type] = $ownerId;
			}
		}

		$entityByActivityId = [];
		foreach ($bindingsByActivityId as $activityId => $bindings)
		{
			foreach ($priority as $type)
			{
				if (!empty($bindings[$type]))
				{
					$entityByActivityId[$activityId] = [
						'TYPE' => mb_strtolower($type),
						'ID' => (int)$bindings[$type],
					];
					break;
				}
			}
		}

		return $entityByActivityId;
	}

	private function enrichWithKpiFirstAnswer(array &$rows): void
	{
		foreach ($rows as &$row)
		{
			$kpiTime = (int)($row['KPI_FIRST_ANSWER_TIME'] ?? 0);
			$waitAnswer = $row['TIME_FIRST_ANSWER'] ?? null;

			// TIME_FIRST_ANSWER defaults to 0 for sessions that were never answered, so
			// a plain "<= kpiTime" comparison would wrongly mark them as meeting the KPI.
			if ($kpiTime <= 0 || $waitAnswer === null || $waitAnswer === '' || (int)$waitAnswer <= 0)
			{
				$row['KPI_FIRST_ANSWER'] = null;
				continue;
			}

			$row['KPI_FIRST_ANSWER'] = (int)$waitAnswer <= $kpiTime;
		}
		unset($row);
	}
}
