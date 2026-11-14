<?php

namespace Bitrix\Call\Service;

use Bitrix\Call\Background\JobDispatcher;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Repository\CallParticipantsRepository;
use Bitrix\Call\Service\CallLog\CallChatMembership;
use Bitrix\Call\Service\CallLog\JournalIndexSearch;
use Bitrix\Call\Service\CallLog\JournalQueryFilter;
use Bitrix\Call\Service\CallLog\JournalSearchCondition;
use Bitrix\Call\Service\CallLog\ParticipantSearch;
use Bitrix\Call\Service\CallLog\SearchExpression;
use Bitrix\Call\Service\Exception\InvalidFilterValueException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Model\CallUserLogCountersTable;
use Bitrix\Call\Model\CallUserLogIndexTable;
use Bitrix\Call\Counter;
use Bitrix\Call\Call;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Application;

class CallLogService
{
	public const MIN_LIMIT = 1;
	public const MAX_LIMIT = 100;
	public const DEFAULT_LIMIT = 50;
	protected const LOCK_TTL = 10; // in seconds

	/**
	 * How many reads of the journal one page of it may cost.
	 *
	 * A page that lost rows to the guest cut is refilled from the rows behind it (@see self::getPage()), and
	 * that refill has to stop somewhere: a journal whose every row is unreachable would otherwise be walked
	 * to its end by a single request.
	 */
	private const MAX_PAGE_READS = 5;

	/**
	 * The kinds of Copilot outcome that light the mark of the row.
	 *
	 * A transcription is left out on purpose: speech written down is not an outcome of the call, and a row
	 * carrying nothing but it must stay unmarked.
	 */
	private const CONTENTFUL_SENSE_TYPES = [
		SenseType::OVERVIEW,
		SenseType::SUMMARY,
		SenseType::INSIGHTS,
		SenseType::EVALUATION,
	];

	/**
	 * How much of a value that came from outside one line of the journal may carry.
	 *
	 * Enough to tell an ATOM string from whatever was sent instead, and short enough that the line costs the
	 * same space however long that value is - @see self::forJournal().
	 */
	private const JOURNAL_VALUE_LIMIT = 64;

	/**
	 * How many characters of a search query are asked; the rest is dropped - @see self::normalizeSearch().
	 */
	private const SEARCH_MAX_LENGTH = 255;

	/**
	 * How many words of a search query are asked: each one is a required term of the fulltext pass, so the number
	 * of words is what a query costs the index. The words past it are dropped - @see self::normalizeSearch().
	 */
	private const SEARCH_MAX_WORDS = 10;

	/**
	 * Seconds an agent that republishes the counter waits before its first run and between its runs
	 * (@see self::republishCounterAgent()).
	 */
	private const REPUBLISH_AGENT_INTERVAL = 60;

	/**
	 * Every status a journal row can carry.
	 *
	 * An enumeration of the column and not a rule about it: which of these stands for which side is answered
	 * by {@see CallLogDirectionResolver::fromStatus()} alone.
	 */
	private const STATUSES = [
		CallUserLogTable::STATUS_INITIATED,
		CallUserLogTable::STATUS_ANSWERED,
		CallUserLogTable::STATUS_DECLINED,
		CallUserLogTable::STATUS_MISSED,
	];

	/**
	 * Chats of a user, by user id, for the lifetime of this service. Filled once per page and reused by the
	 * enrichment of every row on it - see {@see self::isChatMember()}.
	 *
	 * @var array<int, array<int>>
	 */
	private array $memberChatIds = [];

	/**
	 * Whether the user is a member of the chat of the call, by user id and then call id, as the last warm-up
	 * found it - see {@see self::preloadChatMembership()}. Asked by the roster alone: the rest of the row is
	 * not narrowed by it.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $chatMembershipByCall = [];

	/**
	 * Guest flag of a user, by user id, for the lifetime of this service. The flag decides the scope of both
	 * halves of the log, so it is asked for on every row, every counter and every push - and it changes only
	 * with the user themselves, never within one operation.
	 *
	 * @var array<int, bool>
	 */
	private array $guestFlags = [];

	/**
	 * Whether every journal row of a user carries a side, by user id, for the lifetime of this service - see
	 * {@see self::isDirectionKnownForEveryRowOf()}.
	 *
	 * @var array<int, bool>
	 */
	private array $directionKnownForEveryRow = [];

	/**
	 * Whether the call has outcomes of a contentful kind, by call id, for the lifetime of this service.
	 *
	 * Kept here rather than behind a class of its own: there is no model, no grouping and no hydration
	 * behind the answer, only a dictionary of a call and a yes or a no.
	 *
	 * @var array<int, bool>
	 */
	private array $followUpFlags = [];

	/**
	 * Whether the journal row has its counter row, by the id of the journal row, as the last read of the
	 * journal found it - see {@see self::preloadUnseenFlags()} - or the last write of this service left it.
	 *
	 * Every write of the counter rows here puts its answer in: the push of an event answers from this memory,
	 * and a mark kept from a read before the write would go out as the mark of the row.
	 *
	 * @var array<int, bool>
	 */
	private array $unseenFlags = [];

	/**
	 * Roster of the calls of the page, for the lifetime of this service.
	 *
	 * Injectable so that a test can count the reads a page costs: the number of them is the whole point of
	 * the warm-up, and a number that is claimed rather than measured is pinned by nothing.
	 */
	private CallParticipantsRepository $participants;

	/**
	 * Whether the rows this service builds carry the roster of the call.
	 *
	 * The roster is drawn by the journal page and by the live event that updates it, and it is not free:
	 * a read of b_call_user per page plus the hydration of up to PREVIEW_LIMIT users per row. A consumer
	 * that draws none of it says so and pays for none of it - the call picker of the entity selector
	 * builds a title and a subtitle out of the same rows and needs no faces to do it.
	 */
	private bool $withParticipants;

	private SearchExpression $searchExpression;

	private JournalIndexSearch $journalIndexSearch;

	private ParticipantSearch $participantSearch;

	/**
	 * The condition the search puts into the query of the page, by owner and then by expression, for the lifetime
	 * of this service; null when the search answers nothing.
	 *
	 * The owner is a level of the key: one hit may ask the service on behalf of different people, and the branch of
	 * the participants names the chats of one of them. The condition is what the sources answer with, so a refill of
	 * the page by getPage() repeats the query of the page and not the sources. It holds no rows, so live events that
	 * rewrite the journal reach every read of it.
	 *
	 * @var array<int, array<string, JournalSearchCondition|null>>
	 */
	private array $searchConditions = [];

	public function __construct(
		?CallParticipantsRepository $participants = null,
		bool $withParticipants = true,
		?JournalIndexSearch $journalIndexSearch = null,
		?ParticipantSearch $participantSearch = null
	)
	{
		$this->participants = $participants ?? new CallParticipantsRepository();
		$this->withParticipants = $withParticipants;
		$this->searchExpression = new SearchExpression();
		$this->journalIndexSearch = $journalIndexSearch ?? new JournalIndexSearch();
		$this->participantSearch = $participantSearch ?? new ParticipantSearch();
	}

	/**
	 * Validate and normalize limit value
	 *
	 * @param int|null $limit Requested limit
	 * @return int Normalized limit between MIN_LIMIT and MAX_LIMIT
	 */
	public static function normalizeLimit(?int $limit = null): int
	{
		if ($limit === null)
		{
			return self::DEFAULT_LIMIT;
		}
		return max(self::MIN_LIMIT, min($limit, self::MAX_LIMIT));
	}

	/**
	 * One page of the journal, enriched and filled up to the size that was asked for.
	 *
	 * The client tells the end of the journal by the size of the page: fewer rows than it asked for means
	 * there is nothing more. An entry whose call data cannot be built is hidden from a guest, so a page
	 * handed over with those rows simply missing would stop their history silently, with older reachable
	 * calls sitting right behind it. The gap is filled from those very rows instead.
	 *
	 * Cutting by the query alone does not cover this: the query cuts by chat membership, while the
	 * enrichment comes back empty for reasons that are not on the row - a call session that no longer
	 * builds, an associated entity that is gone - and none of them is a join.
	 *
	 * The refill costs at most MAX_PAGE_READS reads. At the end of the journal the page is short, and there
	 * that is exactly what it means.
	 *
	 * @param int $userId
	 * @param array $filter
	 * @param int $lastId
	 * @param int $limit
	 * @param string|null $lastStatusTime Time of the row $lastId stands at, as the answer handed it over.
	 * @return array Formatted rows, each with its own 'callData'.
	 * @throws InvalidFilterValueException If a filter key carries a value of an unusable type or a status the
	 *     journal does not know
	 */
	public function getPage(
		int $userId,
		array $filter = [],
		int $lastId = 0,
		int $limit = self::DEFAULT_LIMIT,
		?string $lastStatusTime = null
	): array
	{
		$limit = self::normalizeLimit($limit);
		// Only a guest is narrowed to what they may open, so only their page can come out short.
		$dropInaccessible = $this->isGuestUser($userId);

		$page = [];
		$cursor = $lastId;
		// A client that sends the pair back seeds the first read with it, so the time of its row is neither
		// read nor missed when the row is already gone; a client that sends the id alone still pays for that
		// read. Every read after the first gets the pair from the rows it has already seen. Without an id
		// there is no cursor at all, so the time is not even looked at: it changes nothing and a defect in a
		// value nothing is done with is not one worth a line of the journal.
		$cursorTime = $lastId > 0 ? self::parseCursorTime($lastStatusTime) : null;
		for ($read = 0; $read < self::MAX_PAGE_READS; $read++)
		{
			$rows = $this->getList($userId, $filter, $cursor, $limit, $cursorTime);
			if ($rows === [])
			{
				break;
			}

			// The cursor the client itself would send next: the LAST row read, not the smallest one, so that
			// the refill walks the journal exactly the way paging walks it. Both halves of the key come from
			// that row - it has just been read, and asking the journal for its time again would cost this
			// loop one query per read.
			[$cursor, $cursorTime] = $this->cursorOf($rows[array_key_last($rows)]);

			// Per read rather than per page: a guest walks the journal up to MAX_PAGE_READS times, and a second
			// read left unwarmed would cost a query of the outcomes per row of it.
			$this->preloadRowData($rows);
			// The roster goes out to the members of the chat alone, and every row of a page has one viewer.
			$this->preloadChatMembership($this->callIdsOf($rows), [$userId]);

			foreach ($rows as $row)
			{
				$row['callData'] = $this->getCallData($row, $userId);
				if ($dropInaccessible && $row['callData'] === [])
				{
					continue;
				}

				$page[] = $row;
			}

			// A read shorter than the limit is the end of the journal: there is nothing left to refill from.
			if (count($page) >= $limit || count($rows) < $limit)
			{
				break;
			}
		}

		return array_slice($page, 0, $limit);
	}

	/**
	 * Get call events for user
	 *
	 * @param int $userId
	 * @param array $filter
	 * @param int $lastId
	 * @param int $limit
	 * @param DateTime|null $cursorTime Time of the row $lastId stands at, when the caller already has it.
	 * @return array
	 * @throws InvalidFilterValueException If a filter key carries a value of an unusable type or a status the
	 *     journal does not know
	 */
	public function getList(
		int $userId,
		array $filter = [],
		int $lastId = 0,
		int $limit = self::DEFAULT_LIMIT,
		?DateTime $cursorTime = null
	): array
	{
		// Build query filter
		$queryFilter = $this->buildQueryFilter($userId, $filter, $lastId, $cursorTime);

		// The search answers nothing before any page is asked
		if ($queryFilter === null)
		{
			return [];
		}

		$rows = $this->fetchAccessibleRows($userId, $queryFilter, $limit);
		$this->preloadUnseenFlags($rows, $userId);

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = $this->formatCallLog($row, $userId);
		}

		return $result;
	}

	/**
	 * Raw journal rows matching the query filter that the user may reach, newest first.
	 *
	 * Shared by every reader of the journal rows - the journal page and the call selection dialog - so that
	 * none of them can skip the guest cut or the owner of the rows.
	 *
	 * The owner is set here, as a condition of the query, and $queryFilter only narrows the selection: a
	 * USER_ID in it does not widen it, and neither does a LOGIC => OR at its top, since the owner condition
	 * is joined to the whole filter by AND. The search is a condition of this very query, so the owner, the
	 * guest cut, the order and the limit cut the rows it answers with as they cut every other row.
	 *
	 * @param int $userId Owner of the rows; a user without an id owns none.
	 * @param JournalQueryFilter|array $queryFilter As buildQueryFilter() makes it, or a filter over CallUserLogTable.
	 * @param int|null $limit
	 * @return array Rows of CallUserLogTable, all fields.
	 */
	public function fetchAccessibleRows(int $userId, JournalQueryFilter|array $queryFilter, ?int $limit = null): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		// A guest sees only the calls of the chats they are still in, and that cut is made by the QUERY rather
		// than by dropping rows from the answer. Dropping after the fact breaks the page: a page short of
		// PER_PAGE tells the client there is nothing more, so the journal stops paging - and a page filtered
		// out whole shows up as an empty journal while older reachable calls sit right behind it.
		// Re-read rather than taken from the cache: the membership is what the page is cut by, and a page
		// must never be cut by the membership the page before it saw.
		$guestChatIds = $this->getGuestChatIds($userId, true);
		if ($guestChatIds === [])
		{
			return [];
		}

		if (is_array($queryFilter))
		{
			$queryFilter = new JournalQueryFilter($queryFilter);
		}

		$query = CallUserLogTable::query()
			->setSelect(['*'])
			->where('USER_ID', $userId)
			->setOrder(['STATUS_TIME' => 'DESC', 'ID' => 'DESC'])
			->setLimit(self::normalizeLimit($limit))
		;
		$queryFilter->applyTo($query);

		if ($guestChatIds !== null)
		{
			$this->restrictToChats($query, $guestChatIds);
		}

		return $query->exec()->fetchAll();
	}

	/**
	 * Chats the user is a member of right now, as ids.
	 *
	 * One query per page rather than one per row: the membership of a page is asked for once and reused,
	 * both by the query that cuts the page and by the enrichment that follows it. An empty list means the
	 * user is in no chat at all, which for a guest is a journal with nothing in it.
	 *
	 * @param int $userId
	 * @return array<int> Chat ids, possibly empty.
	 */
	private function getMemberChatIds(int $userId): array
	{
		if ($userId <= 0 || !\Bitrix\Main\Loader::includeModule('im'))
		{
			return [];
		}

		$chatIds = [];
		$rows = \Bitrix\Im\Model\RelationTable::getList([
			'select' => ['CHAT_ID'],
			'filter' => ['=USER_ID' => $userId],
		]);
		while ($row = $rows->fetch())
		{
			$chatIds[] = (int)$row['CHAT_ID'];
		}

		return $chatIds;
	}

	/**
	 * Chats that bound what the user may see, or null when nothing bounds them.
	 *
	 * A guest is bound by the chats they are still in: their access boundary is the chat link rather than
	 * the portal, so a chat they were removed from takes its calls with it. Everybody else is bound by
	 * nothing beyond the ownership of the journal row itself, and null says exactly that - not "no chats",
	 * which is what an empty list means and what makes a guest journal empty.
	 *
	 * @param int $userId
	 * @param bool $refresh Re-read the membership instead of reusing what this service already read.
	 * @return array<int>|null
	 */
	private function getGuestChatIds(int $userId, bool $refresh = false): ?array
	{
		if (!$this->isGuestUser($userId))
		{
			return null;
		}

		if ($refresh || !isset($this->memberChatIds[$userId]))
		{
			$this->memberChatIds[$userId] = $this->getMemberChatIds($userId);
		}

		return $this->memberChatIds[$userId];
	}

	/**
	 * Narrows a query over b_call_userlog down to the calls that belong to the given chats.
	 *
	 * The chat of a journal row lives on the call session rather than on the row, hence the join. Rows of
	 * another source type (voximplant) carry no call session and fall out of the join, which is what the
	 * cut is meant to do: a guest has no telephony of their own.
	 *
	 * @param Query $query Query over CallUserLogTable.
	 * @param array<int> $chatIds Non-empty; an empty list would leave the query unrestricted (@see Query::whereIn()).
	 * @return void
	 */
	private function restrictToChats(Query $query, array $chatIds): void
	{
		$query->registerRuntimeField(
			'CALL_SESSION',
			new Reference(
				'CALL_SESSION',
				CallTable::class,
				Join::on('this.SOURCE_CALL_ID', 'ref.ID')
					->where('this.SOURCE_TYPE', CallUserLogTable::SOURCE_TYPE_CALL),
				['join_type' => Join::TYPE_LEFT]
			)
		);
		$query->whereIn('CALL_SESSION.CHAT_ID', $chatIds);
	}

	/**
	 * Query over the missed-counter rows of the user, narrowed to what they may reach.
	 *
	 * The narrowing is a JOIN rather than a list of ids read into PHP: rows hidden from a guest are
	 * deliberately not deleted (see markAsSeen), so their number is bounded by nothing, and this query
	 * sits on the read path of the journal and on every counter push.
	 *
	 * @param int $userId
	 * @param array<int>|null $guestChatIds Non-empty chat ids, or null when nothing narrows the user.
	 * @return Query
	 */
	private function createCounterQuery(int $userId, ?array $guestChatIds): Query
	{
		$query = CallUserLogCountersTable::query()->where('USER_ID', $userId);
		if ($guestChatIds === null)
		{
			return $query;
		}

		$query
			->where('USERLOG.SOURCE_TYPE', CallUserLogTable::SOURCE_TYPE_CALL)
			->registerRuntimeField(
				'CALL_SESSION',
				new Reference(
					'CALL_SESSION',
					CallTable::class,
					Join::on('this.USERLOG.SOURCE_CALL_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->whereIn('CALL_SESSION.CHAT_ID', $guestChatIds)
		;

		return $query;
	}

	/**
	 * Keeps of the given journal rows the ones the user may actually reach.
	 *
	 * The same cut as the list makes, applied to ids that come from outside the list: the counter counts
	 * them and mark-as-seen writes to them, and both must stay inside the scope the reader is allowed.
	 *
	 * @param int $userId
	 * @param array<int> $logIds
	 * @return array<int>
	 */
	private function filterAccessibleLogIds(int $userId, array $logIds): array
	{
		$logIds = array_values(array_unique(array_filter(array_map('intval', $logIds), static fn(int $id) => $id > 0)));

		$guestChatIds = $this->getGuestChatIds($userId);
		if ($guestChatIds === null || $logIds === [])
		{
			return $logIds;
		}
		if ($guestChatIds === [])
		{
			return [];
		}

		$query = CallUserLogTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->whereIn('ID', $logIds)
		;
		$this->restrictToChats($query, $guestChatIds);

		return array_map('intval', array_column($query->fetchAll(), 'ID'));
	}

	/**
	 * Ids of the journal rows that make up the missed counter the user can actually see.
	 *
	 * Cut by the query rather than read whole and filtered afterwards: for a guest the rows of a chat
	 * they were removed from stay in the table for good, so reading all of them first would grow without
	 * bound on the very path that clears the badge.
	 *
	 * @param int $userId
	 * @return array<int>
	 */
	private function getAccessibleCounterLogIds(int $userId): array
	{
		$guestChatIds = $this->getGuestChatIds($userId);
		if ($guestChatIds === [])
		{
			return [];
		}

		return array_map(
			'intval',
			array_column(
				$this->createCounterQuery($userId, $guestChatIds)->setSelect(['USERLOG_ID'])->fetchAll(),
				'USERLOG_ID'
			)
		);
	}

	/**
	 * Build query filter from user filter
	 *
	 * EXCLUDE_STATUS subtracts the rows of one status from the selection, and it is read whatever STATUS says:
	 * the gate on STATUS belongs to TYPE alone, where a status of its own outranks the side. A request asking
	 * for a status and against it at once is answered by nothing, which is the honest answer to a contradictory
	 * request.
	 *
	 * TYPE selects the rows of one side in one of two shapes. An owner whose every row carries a side gets the
	 * equality '=DIRECTION' at the top of the filter, the one IX_CALL_USERLOG_DIRECTION_USER_TIME serves; an owner
	 * who still has a row without a side gets the union of {@see self::buildDirectionCondition()} as a group of its
	 * own. Which of the two is answered by {@see self::isDirectionKnownForEveryRowOf()}, and only when TYPE is read
	 * at all.
	 *
	 * The condition of EXCLUDE_STATUS is put at the TOP level of the filter on purpose, whichever shape the side
	 * takes: next to the equality, and around the union, where the conjunction cuts both of its branches - the row
	 * that carries a side and the row written before the journal kept one. Carried inside the second branch
	 * instead, the exclusion would never reach a row that carries a side.
	 *
	 * @param int $userId
	 * @param array $filter Filter parameters (STATUS, TYPE, EXCLUDE_STATUS, SEARCH)
	 * @param int $lastId Last ID for pagination
	 * @param DateTime|null $cursorTime Time of the row $lastId stands at, when the caller already has it.
	 * @return JournalQueryFilter|null Null when the search answers nothing before any page is asked
	 * @throws InvalidFilterValueException If a filter key carries a value of an unusable type or a status the
	 *     journal does not know
	 */
	public function buildQueryFilter(
		int $userId,
		array $filter = [],
		int $lastId = 0,
		?DateTime $cursorTime = null
	): ?JournalQueryFilter
	{
		$queryFilter = ['USER_ID' => $userId];

		// Status filter
		if (!empty($filter['STATUS']))
		{
			$status = $filter['STATUS'];
			$queryFilter['@STATUS'] = $this->statusesNamedBy(is_array($status) ? $status : [$status], 'STATUS');
		}

		// Checked outside the branch below: the key is refused whenever it is set, even when STATUS has
		// already taken priority over it and the value would never be read. An empty array is refused too -
		// this key takes one value and never a list, so reading [] as "the condition is not set" would answer
		// an unusable filter with the whole journal.
		if (isset($filter['TYPE']) && !is_scalar($filter['TYPE']))
		{
			throw new InvalidFilterValueException('TYPE');
		}

		// Type filter (incoming/outgoing)
		if (!empty($filter['TYPE']) && empty($queryFilter['@STATUS']))
		{
			$direction = strtolower((string)$filter['TYPE']);
			if (
				$direction === CallUserLogTable::DIRECTION_INCOMING
				|| $direction === CallUserLogTable::DIRECTION_OUTGOING
			)
			{
				if ($this->isDirectionKnownForEveryRowOf($userId))
				{
					$queryFilter['=DIRECTION'] = $direction;
				}
				else
				{
					$queryFilter[] = $this->buildDirectionCondition($direction);
				}
			}
		}

		// Refused whenever it is set, as TYPE is: the key takes one status and never a list, so an empty array
		// read as "nothing to exclude" would answer an unusable filter with the rows it asked to leave out.
		if (isset($filter['EXCLUDE_STATUS']) && !is_scalar($filter['EXCLUDE_STATUS']))
		{
			throw new InvalidFilterValueException('EXCLUDE_STATUS');
		}

		// Status exclusion filter
		if (!empty($filter['EXCLUDE_STATUS']))
		{
			$queryFilter['!=STATUS'] = $this->statusesNamedBy([$filter['EXCLUDE_STATUS']], 'EXCLUDE_STATUS')[0];
		}

		// A refusal is not the empty result below: null says the search ran and matched nothing, and the
		// consumers answer the two differently. An empty array is refused as well, for the same reason as
		// TYPE: a term is one value, and [] read as "not searching" would widen the answer instead.
		if (isset($filter['SEARCH']) && !is_scalar($filter['SEARCH']))
		{
			throw new InvalidFilterValueException('SEARCH');
		}

		$searchCondition = null;
		$search = $this->normalizeSearch($filter);
		if ($search !== '')
		{
			$searchCondition = $this->searchConditionOf($search, $userId);
			if ($searchCondition === null)
			{
				return null;
			}
		}

		// Pagination is built last, after every branch that can answer with no query at all: the cursor costs
		// a read of the journal, and a search that matched nothing has no page to cut.
		if ($lastId > 0)
		{
			$queryFilter = array_merge($queryFilter, $this->cursorCondition($userId, $lastId, $cursorTime));
		}

		return new JournalQueryFilter($queryFilter, $searchCondition);
	}

	/**
	 * Whether the filter asks the journal to search.
	 *
	 * One answer for the query of the page and for the choice of where the counter of the answer comes from:
	 * a request the query reads as a search must not be read as the first page by the counter, nor the other
	 * way round.
	 *
	 * @param array $filter Filter parameters (STATUS, TYPE, EXCLUDE_STATUS, SEARCH)
	 * @return bool
	 */
	private function hasSearch(array $filter): bool
	{
		return $this->normalizeSearch($filter) !== '';
	}

	/**
	 * The search string of a list request, empty when it carries none.
	 *
	 * Every scalar is a query: a phone number sent over REST arrives as a number, and '0' is a query like any
	 * other. A value that is not a scalar is no search here - buildQueryFilter() refuses it before asking.
	 *
	 * A query over the limits is cut to them rather than refused: to its first SEARCH_MAX_LENGTH characters, then
	 * to its first SEARCH_MAX_WORDS words, the rest dropped. Text pasted into the field is longer than anyone types,
	 * and a refusal would turn it into an error of the whole list. A word the length cuts through is kept as its
	 * beginning, which is how every word is asked anyway, and a word named twice is one term and is counted once.
	 * A query within the limits is left as it is - a query of signs alone has no word to count, and it must stay a
	 * search that answers nothing.
	 *
	 * @param array $filter Filter parameters (STATUS, TYPE, EXCLUDE_STATUS, SEARCH)
	 * @return string The trimmed search string
	 */
	private function normalizeSearch(array $filter): string
	{
		$search = $filter['SEARCH'] ?? null;
		if (!is_scalar($search))
		{
			return '';
		}

		$search = rtrim(mb_substr(trim((string)$search), 0, self::SEARCH_MAX_LENGTH));

		$words = Helper::splitWords($search);
		if (count($words) > self::SEARCH_MAX_WORDS)
		{
			return implode(' ', array_slice($words, 0, self::SEARCH_MAX_WORDS));
		}

		return $search;
	}

	/**
	 * The statuses of the journal a filter key names: in lower case, each once, in the order they are named.
	 *
	 * One dictionary for every key of the filter that names a status, and a value outside it reaches no query.
	 * A list is refused whole rather than cleaned: applying what is left of it would run the query against a
	 * narrower condition than the one that was asked for, and say nothing about it. Nor is the value matched
	 * by LIKE: % and _ are patterns there, and a request would select by a pattern nobody declared.
	 *
	 * @param array $values Values of the key; a single value comes wrapped into a list of one.
	 * @param string $filterKey The key the values came in, named by the refusal.
	 * @return string[] Statuses of {@see self::STATUSES}.
	 * @throws InvalidFilterValueException If a value is not a scalar or not a status of the journal
	 */
	private function statusesNamedBy(array $values, string $filterKey): array
	{
		$statuses = [];
		foreach ($values as $value)
		{
			if (!is_scalar($value))
			{
				throw new InvalidFilterValueException($filterKey);
			}

			$status = strtolower((string)$value);
			if (!in_array($status, self::STATUSES, true))
			{
				throw new InvalidFilterValueException($filterKey);
			}

			$statuses[] = $status;
		}

		return array_values(array_unique($statuses));
	}

	/**
	 * The condition a tab of the journal picks the rows of one side by.
	 *
	 * A row that carries a side is picked by it, a row written before the journal kept one - by its status,
	 * the way the page reads it ({@see self::resolveType()}). Selection and answer are one rule on purpose: a
	 * row picked by one and shown by the other would appear under a tab that contradicts it.
	 *
	 * The union is built only while the owner still has rows without a side: no index serves it. Without such rows
	 * the tab is the equality on DIRECTION alone, which IX_CALL_USERLOG_DIRECTION_USER_TIME serves in the order of
	 * the journal - see {@see self::buildQueryFilter()}.
	 *
	 * A sub-filter of the array rather than a ConditionTree: the conditions of the filter are handed on whole to
	 * setFilter() ({@see JournalQueryFilter::applyTo()}), the call selection dialog included, and an array filter
	 * carries no condition object - see {@see \Bitrix\Call\Integration\UI\EntitySelector\CallLogProvider}.
	 *
	 * @param string $direction DIRECTION_INCOMING or DIRECTION_OUTGOING of {@see CallUserLogTable}
	 * @return array Sub-filter of the query filter of the journal
	 */
	private function buildDirectionCondition(string $direction): array
	{
		return [
			'LOGIC' => 'OR',
			['=DIRECTION' => $direction],
			[
				'=DIRECTION' => null,
				'@STATUS' => $this->statusesReadAs($direction),
			],
		];
	}

	/**
	 * Whether every journal row of the user carries a side, so that a tab of theirs is selected by the side alone.
	 *
	 * One probe for a row without a side - '=DIRECTION' => null stands for null and for an empty string alike -
	 * which the leading pair (DIRECTION, USER_ID) of IX_CALL_USERLOG_DIRECTION_USER_TIME serves. The answer is kept
	 * for the lifetime of the service: a page builds its filter for every read of the journal, up to MAX_PAGE_READS
	 * times.
	 *
	 * An answer gone stale is wrong only the safe way. The live write always names a side - the resolvers answer
	 * with one - and the pass of the journal and the live write only ever fill a side in: a journal found with a
	 * side on every row stays so, while a row found without one may have got it since, which costs the union alone.
	 *
	 * The probe looks at every row of the user, with no cut of a guest to the chats they are in: the rows a guest
	 * reaches are a subset, and a row without a side among the ones they do not reach keeps them on the union,
	 * which answers the same rows.
	 */
	private function isDirectionKnownForEveryRowOf(int $userId): bool
	{
		if (isset($this->directionKnownForEveryRow[$userId]))
		{
			return $this->directionKnownForEveryRow[$userId];
		}

		$rowWithoutASide = CallUserLogTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->setFilter(['=DIRECTION' => null])
			->setLimit(1)
			->fetch()
		;

		return $this->directionKnownForEveryRow[$userId] = !$rowWithoutASide;
	}

	/**
	 * The statuses a row without a side of its own is read as the given side by.
	 *
	 * Folded through {@see CallLogDirectionResolver::fromStatus()} rather than listed here a second time:
	 * which status stands for which side is one rule with one home, and what the journal selects has to move
	 * with what it shows.
	 *
	 * @param string $direction DIRECTION_INCOMING or DIRECTION_OUTGOING of {@see CallUserLogTable}
	 * @return string[]
	 */
	private function statusesReadAs(string $direction): array
	{
		$resolver = new CallLogDirectionResolver();

		return array_values(array_filter(
			self::STATUSES,
			static fn(string $status): bool => $resolver->fromStatus($status) === $direction,
		));
	}

	/**
	 * Cuts the page by the key the journal is ordered by: STATUS_TIME first, ID to separate one second.
	 *
	 * The client carries only the id of the last row it received, so the time half of the cursor is read
	 * here by that id. A cut by the id alone is not the same order: STATUS_TIME of a row is rewritten by
	 * addOrUpdateEvent() while the id stays from the moment the row was created, so a call started earlier
	 * and finished later carries a smaller id at a larger time. Such a row sits behind an id-only cut and
	 * returns on no page at all - it is lost rather than postponed.
	 *
	 * @param int $userId Owner of the journal being paged.
	 * @param int $lastId Id of the last row the client received.
	 * @param DateTime|null $cursorTime Time of that row when the caller already has it; read here otherwise.
	 * @return array Condition to merge into the filter being built: a key of its own for the cut by the id,
	 *     a group of one element for the cut by the pair.
	 */
	private function cursorCondition(int $userId, int $lastId, ?DateTime $cursorTime): array
	{
		$cursorTime ??= $this->getCursorStatusTime($userId, $lastId);
		if ($cursorTime === null)
		{
			// The row the client points at is gone: its time cannot be read any more, and the id is all the
			// cursor has left. This is what paging did before, losses included - the fallback is a floor, not
			// a fix. And it is not a rare corner: besides retention cleanup and chat delete, the user reaches
			// it by deleting the last entry they loaded (CallLog::deleteAction), because the client keeps the
			// lastId it took from that entry. Paging by the pair itself is what closes this - the client
			// already receives statusTime on every row and could send it back.
			return ['<ID' => $lastId];
		}

		return [[
			'LOGIC' => 'OR',
			['<STATUS_TIME' => $cursorTime],
			['=STATUS_TIME' => $cursorTime, '<ID' => $lastId],
		]];
	}

	/**
	 * The pair a row read from the journal stands at: its id and its time.
	 *
	 * Taken from the row itself, so a page refilled from several reads costs no query per read. The time
	 * comes back formatted for the answer, and an unreadable one gives null - then the cursor falls back to
	 * reading the row, which is what it does for the id the client sends.
	 *
	 * @param array $row Formatted row, @see self::formatCallLog()
	 * @return array{0: int, 1: DateTime|null}
	 */
	private function cursorOf(array $row): array
	{
		$formatted = $row['statusTime'] ?? null;

		return [(int)$row['id'], self::parseCursorTime(is_string($formatted) ? $formatted : null)];
	}

	/**
	 * The time half of the cursor, read from the string the answer itself handed over.
	 *
	 * One definition of the format for both cursors - the one the client sends back and the one the refill
	 * takes from a row it has just read - so the two cannot drift apart. Nothing to parse is not an error:
	 * the parameter is optional, and without it paging works the way it worked before the pair existed. A
	 * value that is there and unreadable is a defect of whoever sent it, so it degrades to the same thing
	 * but goes to the journal rather than passing silently. Unreadable covers a time out of range as well
	 * as a string of the wrong shape, @see self::parsedWithComplaints().
	 *
	 * @param string|null $raw Value of the statusTime field of a row, @see \DateTime::ATOM
	 * @return DateTime|null
	 */
	public static function parseCursorTime(?string $raw): ?DateTime
	{
		if ($raw === null || $raw === '')
		{
			return null;
		}

		$parsed = \DateTime::createFromFormat(\DateTime::ATOM, $raw);
		if (!$parsed || self::parsedWithComplaints())
		{
			Logger::getInstance()->error(
				'Call log cursor time is not an ATOM string: "' . self::forJournal($raw) . '"'
			);

			return null;
		}

		return DateTime::createFromPhp($parsed);
	}

	/**
	 * Whether the parse just made had anything to report about the string it was given.
	 *
	 * createFromFormat() refuses a string that does not match the format, but a value out of range it
	 * carries over instead of refusing: 2026-13-45T99:99:99+03:00 comes back as 2027-02-18, and the zero
	 * date of a database as a year before the era, which no DATETIME column holds. As a cursor such a time
	 * is no better than a string of the wrong shape - it cuts the page by a moment the journal could never
	 * have written - and the warnings of the parse are the only thing that tells it from a time that was
	 * really sent. Nothing to report reads as false since PHP 8.2 and as zero counts before it.
	 *
	 * @return bool
	 */
	private static function parsedWithComplaints(): bool
	{
		$state = \DateTime::getLastErrors();

		return $state !== false && ($state['warning_count'] > 0 || $state['error_count'] > 0);
	}

	/**
	 * A value that came from outside, made safe to quote in one line of the journal.
	 *
	 * The value is what makes such a line worth writing - it is the only thing that tells a client
	 * formatting the field wrong from one truncating it - but it is also a string the sender chose. Line
	 * breaks in it would forge records of the journal, and its length alone would let repeated requests
	 * grow the file, so control characters go in as their escapes and the tail beyond the limit is dropped.
	 *
	 * @param string $raw Value as it was received.
	 * @return string Single-line value of at most self::JOURNAL_VALUE_LIMIT characters, plus an ellipsis
	 *     when it was cut.
	 */
	private static function forJournal(string $raw): string
	{
		$cut = mb_substr($raw, 0, self::JOURNAL_VALUE_LIMIT);

		return addcslashes($cut, "\0..\37\177\\") . ($cut === $raw ? '' : '...');
	}

	/**
	 * Time of the row the cursor points at, or null when the journal no longer has it.
	 *
	 * Read by the primary key and scoped exactly the way the page itself is scoped: by the owner, because
	 * the id comes from outside and a foreign row must not tell the caller anything, and for a guest by the
	 * chats they are still in, because a row of a chat they left is not theirs to page by either. A row the
	 * scope does not reach reads as gone, and the cursor falls back to the id alone.
	 *
	 * Only the read lives here; what a row read means is decided by self::cursorTimeOfRow().
	 */
	private function getCursorStatusTime(int $userId, int $lastId): ?DateTime
	{
		$guestChatIds = $this->getGuestChatIds($userId);
		if ($guestChatIds === [])
		{
			return null;
		}

		$query = CallUserLogTable::query()
			->setSelect(['STATUS_TIME'])
			->where('ID', $lastId)
			->where('USER_ID', $userId)
		;

		if ($guestChatIds !== null)
		{
			$this->restrictToChats($query, $guestChatIds);
		}

		return $this->cursorTimeOfRow($query->fetch(), $lastId);
	}

	/**
	 * The time the row read for the cursor stands at, or null when that row gives none.
	 *
	 * Both outcomes end in the same fallback to the id alone, but they are not the same event. No row is
	 * the ordinary path of the feature - the user deletes the entry the client holds the cursor of, and
	 * retention or a chat delete take rows away just as quietly - so a line per such read would put the
	 * journal under the traffic of paging itself. A row whose STATUS_TIME is not a time is a defect: the
	 * column is declared NOT NULL and typed, nothing on the write path produces such a row, and the
	 * degradation into the id-only cut is invisible to whoever caused it.
	 *
	 * @param array|false $row Row read by the cursor id, @see Query::fetch()
	 * @param int $lastId Id of that row, for the journal - the row itself is selected without it.
	 * @return DateTime|null
	 */
	private function cursorTimeOfRow(array|false $row, int $lastId): ?DateTime
	{
		if ($row === false)
		{
			return null;
		}

		$statusTime = $row['STATUS_TIME'] ?? null;
		if (!($statusTime instanceof DateTime))
		{
			Logger::getInstance()->error(
				"Call log cursor row {$lastId} carries STATUS_TIME of type " . get_debug_type($statusTime)
			);

			return null;
		}

		return $statusTime;
	}

	/**
	 * Format call log row to API format
	 *
	 * @param array $row Raw database row
	 * @param int $userId User ID for checking unseen status
	 * @return array Formatted call log data
	 */
	public function formatCallLog(array $row, int $userId): array
	{
		$isUnseen = $this->resolveUnseen((string)$row['STATUS'], (int)$row['ID'], $userId);

		return [
			'id' => (int)$row['ID'],
			'sourceType' => $row['SOURCE_TYPE'],
			'sourceCallId' => (int)$row['SOURCE_CALL_ID'],
			'status' => $row['STATUS'],
			'statusTime' => $row['STATUS_TIME'] instanceof DateTime
				? $row['STATUS_TIME']->format(\DateTime::ATOM)
				: $row['STATUS_TIME'],
			'type' => $this->resolveType($row['DIRECTION'] ?? null, (string)$row['STATUS']),
			'isUnseen' => $isUnseen
		];
	}

	/**
	 * Side of a call log entry, as the client reads it
	 *
	 * The side the row carries, and for a row written before the journal kept one - the side its status
	 * stands for. The single home of that fallback on the way out: the page and the push both answer through
	 * it, or they would part on the same row. Which status stands for which side is decided by
	 * {@see CallLogDirectionResolver::fromStatus()} alone.
	 *
	 * @param string|null $direction DIRECTION of the row; anything but DIRECTION_* of {@see CallUserLogTable}
	 *                               is read as no side
	 * @param string $status STATUS of the row
	 * @return string Either DIRECTION_INCOMING or DIRECTION_OUTGOING of {@see CallUserLogTable}
	 */
	private function resolveType(?string $direction, string $status): string
	{
		if (
			$direction === CallUserLogTable::DIRECTION_INCOMING
			|| $direction === CallUserLogTable::DIRECTION_OUTGOING
		)
		{
			return $direction;
		}

		return (new CallLogDirectionResolver())->fromStatus($status);
	}

	/**
	 * Unseen mark of a call log entry, as the client reads it
	 *
	 * Only a missed call can be unseen, and it is unseen while its counter row is there: the status stays
	 * 'missed' after the badge is cleared. The single home of the rule on the way out - the page and the push
	 * both answer through it, or they would part on the same row.
	 *
	 * @param string $status STATUS of the row
	 * @param int $callLogId
	 * @param int $userId Owner of the row
	 * @return bool
	 */
	private function resolveUnseen(string $status, int $callLogId, int $userId): bool
	{
		return $status === CallUserLogTable::STATUS_MISSED && $this->isUnseen($callLogId, $userId);
	}

	/**
	 * Check if call log entry is unseen
	 *
	 * Answered from the last read of the journal that held the row or the last write of its counter row by
	 * this service, and read on the spot for a row neither has touched.
	 *
	 * @param int $callLogId
	 * @param int $userId
	 * @return bool
	 */
	private function isUnseen(int $callLogId, int $userId): bool
	{
		if (array_key_exists($callLogId, $this->unseenFlags))
		{
			return $this->unseenFlags[$callLogId];
		}

		$counter = CallUserLogCountersTable::getList([
			'select' => ['ID'],
			'filter' => [
				'USERLOG_ID' => $callLogId,
				'USER_ID' => $userId
			],
			'limit' => 1
		])->fetch();

		return $counter !== false;
	}

	/**
	 * Warms the unseen mark of the missed rows of one read of the journal with one query of the counters,
	 * which isUnseen() would otherwise ask once per missed row.
	 *
	 * Keyed by the journal row alone: the rows of a read belong to the user the read is made for, and the
	 * counter row of a journal row is only ever written for its owner.
	 *
	 * Every id of the read is written over, known or not: the mark changes within the lifetime of a service,
	 * and an answer kept from an earlier read would outlive the row being marked as seen.
	 *
	 * @param array $rows Raw rows of CallUserLogTable.
	 * @param int $userId Owner of the rows.
	 * @return void
	 */
	private function preloadUnseenFlags(array $rows, int $userId): void
	{
		$callLogIds = [];
		foreach ($rows as $row)
		{
			if ($row['STATUS'] === CallUserLogTable::STATUS_MISSED)
			{
				$callLogIds[] = (int)$row['ID'];
			}
		}

		if ($callLogIds === [])
		{
			return;
		}

		$counters = CallUserLogCountersTable::query()
			->setSelect(['USERLOG_ID'])
			->whereIn('USERLOG_ID', $callLogIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;

		foreach ($callLogIds as $callLogId)
		{
			$this->unseenFlags[$callLogId] = false;
		}
		foreach ($counters as $counter)
		{
			$this->unseenFlags[(int)$counter['USERLOG_ID']] = true;
		}
	}

	/**
	 * Get missed calls counter for user
	 *
	 * The counter counts what the journal shows, and for a guest that is less than what the table holds:
	 * a call of a chat they were removed from stays in b_call_userlog and keeps its counter row, while the
	 * list no longer returns it. Counted without the same cut, the badge would tell a guest about calls
	 * they cannot open, and mark-as-seen would then hand them the ids of those very rows.
	 *
	 * @param int $userId
	 * @return int
	 */
	public function getMissedCounter(int $userId): int
	{
		$guestChatIds = $this->getGuestChatIds($userId);
		if ($guestChatIds === [])
		{
			return 0;
		}

		return (int)$this->createCounterQuery($userId, $guestChatIds)->queryCountTotal();
	}

	/**
	 * Everyone who joined the call, for the participants layer of the journal row - or null when the call
	 * is not the caller's to look at.
	 *
	 * The boundary is the one the page already draws and no second one is introduced. The call is the
	 * caller's when they have a journal row of their own about it - a guest only while still in its chat,
	 * cut by the same join as the page. Who of them gets the faces is decided by isRosterReader(), the gate
	 * the roster of the row goes through: whoever is no longer in the chat keeps the row and gets an empty
	 * roster, exactly what the row of the page carries for them.
	 *
	 * A call that does not exist and a call of somebody else's journal answer alike: telling them apart
	 * would turn the method into a probe for the existence of a call by its id.
	 *
	 * @param int $userId Current user.
	 * @param int $sourceCallId Id of the call SESSION - the value b_call_userlog carries in SOURCE_CALL_ID,
	 *                          not the id of the journal row itself.
	 * @return array<int, array{id: int, name: string, avatar: string, color: string}>|null
	 */
	public function getParticipantsForUser(int $userId, int $sourceCallId): ?array
	{
		if ($userId <= 0 || $sourceCallId <= 0)
		{
			return null;
		}

		// Strict on purpose: getGuestChatIds() answers null for everybody who is not a guest, and null == []
		// holds in PHP - a loose check here would refuse every employee there is.
		$guestChatIds = $this->getGuestChatIds($userId);
		if ($guestChatIds === [])
		{
			return null;
		}

		$query = CallUserLogTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('SOURCE_TYPE', CallUserLogTable::SOURCE_TYPE_CALL)
			->where('SOURCE_CALL_ID', $sourceCallId)
			->setLimit(1)
		;
		if ($guestChatIds !== null)
		{
			$this->restrictToChats($query, $guestChatIds);
		}

		if (!$query->fetch())
		{
			return null;
		}

		// The real chat of the call and not a placeholder: the gate answers a guest from the chats already
		// read and anybody else from b_im_relation, and a chat of nought is a member of neither.
		$call = CallTable::getRow([
			'select' => ['CHAT_ID'],
			'filter' => ['=ID' => $sourceCallId],
		]);
		$chatId = (int)($call['CHAT_ID'] ?? 0);

		if (!$this->isRosterReader($sourceCallId, $chatId, $userId))
		{
			return [];
		}

		return $this->participants->getAll($sourceCallId);
	}

	/**
	 * Get call data based on source type
	 *
	 * @param array $call Call log entry (from b_call_userlog)
	 * @param int $userId Current user ID
	 * @return array
	 */
	public function getCallData(array $call, int $userId): array
	{
		$sourceType = $call['sourceType'] ?? $call['SOURCE_TYPE'] ?? '';
		if ($sourceType === CallUserLogTable::SOURCE_TYPE_VOXIMPLANT)
		{
			return $this->getVoximplantCallData($call, $userId);
		}
		elseif ($sourceType === CallUserLogTable::SOURCE_TYPE_CALL)
		{
			return $this->getImCallData($call, $userId);
		}
		return [];
	}

	/**
	 * Get voximplant call data
	 *
	 * @param array $call Call log entry from b_call_userlog
	 * @param int $currentUserId Current user ID (to determine the other participant)
	 * @return array
	 */
	private function getVoximplantCallData(array $call, int $currentUserId): array
	{
		if (!\Bitrix\Main\Loader::includeModule('voximplant'))
		{
			return [];
		}

		$sourceCallId = (int)($call['sourceCallId'] ?? $call['SOURCE_CALL_ID'] ?? 0);
		$status = $call['status'] ?? $call['STATUS'] ?? '';

		// Get statistic record by ID
		$statisticRecord = \Bitrix\Voximplant\StatisticTable::getById($sourceCallId)->fetch();
		if (!$statisticRecord)
		{
			return [];
		}

		// For missed and declined calls, duration should be 0
		$duration = (int)($statisticRecord['CALL_DURATION'] ?? 0);
		if (in_array($status, [CallUserLogTable::STATUS_MISSED, CallUserLogTable::STATUS_DECLINED]))
		{
			$duration = 0;
		}

		$result = [
			'phoneNumber' => $statisticRecord['PHONE_NUMBER'] ?: '',
			'duration' => $duration
		];

		$isInternalCall = $this->isInternalVoximplantCall($sourceCallId);
		if ($isInternalCall)
		{
			// Internal call - get other participant info
			$otherParticipant = $this->getOtherVoximplantParticipant($sourceCallId, $currentUserId);

			if ($otherParticipant)
			{
				$result['displayName'] = $otherParticipant['displayName'];
				$result['userId'] = $otherParticipant['userId'];

				// For receiver, show initiator's internal number
				if (isset($otherParticipant['phoneNumber']))
				{
					$result['phoneNumber'] = $otherParticipant['phoneNumber'];
				}
			}
		}
		else
		{
			// External call - try to get contact name from CRM if available
			if ($statisticRecord['CRM_ENTITY_TYPE'] && $statisticRecord['CRM_ENTITY_ID'])
			{
				$displayName = $this->getCrmEntityName(
					$statisticRecord['CRM_ENTITY_TYPE'],
					$statisticRecord['CRM_ENTITY_ID']
				);
				if ($displayName)
				{
					$result['displayName'] = $displayName;
				}
			}

			// Fallback to phone number as display name
			if (empty($result['displayName']))
			{
				$result['displayName'] = $result['phoneNumber'] ?: 'Unknown';
			}
		}

		return $result;
	}

	/**
	 * Check if voximplant call is internal (between two users)
	 *
	 * @param int $sourceCallId Voximplant statistic ID
	 * @return bool
	 */
	private function isInternalVoximplantCall(int $sourceCallId): bool
	{
		$count = CallUserLogTable::getCount([
			'=SOURCE_TYPE' => CallUserLogTable::SOURCE_TYPE_VOXIMPLANT,
			'=SOURCE_CALL_ID' => $sourceCallId
		]);

		return $count > 1;
	}

	/**
	 * Get other participant info for internal voximplant call
	 *
	 * @param int $sourceCallId Voximplant statistic ID
	 * @param int $currentUserId Current user ID
	 * @return array|null
	 */
	private function getOtherVoximplantParticipant(int $sourceCallId, int $currentUserId): ?array
	{
		$logEntries = CallUserLogTable::getList([
			'filter' => [
				'=SOURCE_TYPE' => CallUserLogTable::SOURCE_TYPE_VOXIMPLANT,
				'=SOURCE_CALL_ID' => $sourceCallId
			],
			'select' => ['USER_ID', 'STATUS'],
			'order' => ['ID' => 'ASC']
		])->fetchAll();

		$otherUserId = null;
		$initiatorId = null;

		foreach ($logEntries as $entry)
		{
			if ($entry['STATUS'] === CallUserLogTable::STATUS_INITIATED)
			{
				$initiatorId = (int)$entry['USER_ID'];
			}
			if ((int)$entry['USER_ID'] !== $currentUserId)
			{
				$otherUserId = (int)$entry['USER_ID'];
			}
		}

		if (!$otherUserId)
		{
			return null;
		}

		$user = \CUser::GetByID($otherUserId)->Fetch();
		if (!$user)
		{
			return null;
		}

		$result = [
			'displayName' => \CUser::FormatName(
				\CSite::GetNameFormat(false),
				$user,
				true,
				false
			),
			'userId' => $otherUserId
		];

		// For the receiver, show initiator's internal Voximplant number
		if ($initiatorId && $initiatorId !== $currentUserId)
		{
			$phoneRecord = \Bitrix\Voximplant\PhoneTable::getList([
				'filter' => ['=USER_ID' => $initiatorId],
				'select' => ['PHONE_NUMBER'],
				'limit' => 1
			])->fetch();

			if ($phoneRecord && $phoneRecord['PHONE_NUMBER'])
			{
				$result['phoneNumber'] = $phoneRecord['PHONE_NUMBER'];
			}
		}

		return $result;
	}

	/**
	 * Get IM call data
	 *
	 * @param array $call Call log entry from b_call_userlog
	 * @param int $currentUserId Current user ID
	 * @return array
	 */
	private function getImCallData(array $call, int $currentUserId): array
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return [];
		}

		$sourceCallId = (int)($call['sourceCallId'] ?? $call['SOURCE_CALL_ID'] ?? 0);

		// Load call object using standard method
		$callObject = Call::loadWithId($sourceCallId);
		if (!$callObject instanceof Call)
		{
			return [];
		}

		// Get associated entity (Chat entity)
		$chatEntity = $callObject->getAssociatedEntity();
		if (!$chatEntity)
		{
			return [];
		}

		// The journal row outlives the chat membership - losing a chat does not delete b_call_userlog rows,
		// and getList() scopes them by USER_ID alone - while everything below reads the CURRENT name, avatar
		// and chat id. For a guest that combination leaks: a guest kicked out of one chat keeps their session
		// while any other link is alive (Im\V2\Guest\Auth\AuthorizationService::invalidateGuestUser), so
		// their own call history would keep answering with live metadata of a chat they can no longer open.
		// Employees are deliberately left with the name and the avatar: the same gap exists for them and predates
		// this method being opened to guests, so narrowing it for everyone belongs to its own task, not to this
		// one. The roster further down is narrowed for everybody - see isRosterReader().
		if ($this->isGuestUser($currentUserId) && !$this->isChatMember((int)$callObject->getChatId(), $currentUserId))
		{
			return [];
		}


		$result = [
			'chatId' => $callObject->getChatId(),
			'title' => $chatEntity->getName($currentUserId) ?: 'Untitled Chat',
			'avatar' => $chatEntity->getAvatar($currentUserId) ?: '',
			'color' => $chatEntity->getAvatarColor($currentUserId) ?: ''
		];

		// Calculate call duration
		$result['duration'] = $this->getCallDuration($callObject);

		// Count users in call
		$result['userCount'] = $callObject->getUserCount();

		// Determine chat type and dialogId
		if ($chatEntity->isPrivateChat())
		{
			$result['dialogId'] = (string)$chatEntity->getEntityId($currentUserId);
			$result['chatType'] = 'private';
		}
		elseif ($callObject->getType() === \Bitrix\Call\Call::TYPE_PERMANENT)
		{
			$result['dialogId'] = 'chat' . $callObject->getChatId();
			$result['chatType'] = 'videoconf';
		}
		else
		{
			$result['dialogId'] = 'chat' . $callObject->getChatId();
			$result['chatType'] = 'group';
		}

		// The roster is a field of the non-private chats alone: on a private call the row already names the
		// other side by its title, and a stack of two faces says nothing the row does not. On a private chat
		// the keys are absent rather than empty - the client tells one from the other.
		// userCount stays as it was, meaning the number of people in the CHAT: the mobile list reads it.
		$callId = (int)$callObject->getId();
		if ($this->withParticipants && $result['chatType'] !== 'private')
		{
			// A roster that cannot be read costs the row its faces and nothing else: the row goes out with an
			// empty one, the way a refused warm-up leaves it. On the push path the row is already written by now,
			// and an exception let out would cost it the push and the update of the counter.
			// The same empty roster goes to whoever is no longer in the chat - see isRosterReader().
			$participants = [];
			$participantsTotal = 0;
			try
			{
				if ($this->isRosterReader($callId, (int)$callObject->getChatId(), $currentUserId))
				{
					$participants = $this->participants->getParticipants($callId);
					$participantsTotal = $this->participants->getTotal($callId);
				}
			}
			catch (\Throwable $exception)
			{
				$participants = [];
				$participantsTotal = 0;
				Logger::getInstance()->error(
					"Failed to read the participants of call {$callId} for user {$currentUserId}: "
					. $exception->getMessage()
				);
			}

			$result['participants'] = $participants;
			$result['participantsTotal'] = $participantsTotal;
		}

		// The mark of the Copilot outcomes belongs to every chat row, the private ones included: outcomes
		// are written per call and not per kind of chat, so a private call earns the mark by exactly what a
		// group one earns it by. This is what tells the field apart from the roster above.
		$result['hasFollowUp'] = $this->hasFollowUp($callId);

		return $result;
	}

	/**
	 * Does the roster of the call go out to the user: only to a member of the chat of the call, as of now.
	 *
	 * The journal row outlives the membership - leaving a chat or being removed from it deletes no row of
	 * b_call_userlog - while the roster names the people of the chat and the time they joined. Whoever is no
	 * longer in the chat keeps the row, its name and its avatar, and gets no faces, whatever their kind of
	 * account.
	 *
	 * Answered by the warm-up of the page, and on the spot for a row outside of one - the push of one event.
	 *
	 * @param int $callId
	 * @param int $chatId Chat of the call; a call without one has no members to show the roster to.
	 * @param int $userId
	 * @return bool
	 */
	private function isRosterReader(int $callId, int $chatId, int $userId): bool
	{
		return $this->chatMembershipByCall[$userId][$callId] ?? $this->isChatMember($chatId, $userId);
	}

	/**
	 * Is the user still a member of the chat the call belongs to.
	 *
	 * Membership is read straight from b_im_relation rather than through Chat::checkAccess(): that method
	 * answers differently across core versions - an object on the newer ones, a plain bool on the older -
	 * and on the older one it stays true for a user who has already been removed from the chat, which is
	 * exactly the case this guard exists for. The relation row is the ground truth on every version.
	 *
	 * The membership of a guest is reused once read: a page of the journal holds many calls of the same
	 * chat, and asking the database once per row would put a query per row into the main guest scenario.
	 * fetchAccessibleRows() fills that cache through getGuestChatIds() in one query before the page is even
	 * cut, and re-reads it for every page. The cache is kept for a guest only: a user who is not a guest has
	 * none, and their membership is asked for on the spot.
	 *
	 * @param int $chatId
	 * @param int $userId
	 * @return bool
	 */
	private function isChatMember(int $chatId, int $userId): bool
	{
		if ($chatId <= 0 || $userId <= 0)
		{
			return false;
		}

		// Filled through getGuestChatIds() - by fetchAccessibleRows() for the page being enriched - and for a
		// guest only: a page holds many calls of the same chat, so asking per row would put a query per row into
		// the main guest scenario. For anybody else there is nothing to reuse, and the membership is asked for
		// on the spot.
		if (isset($this->memberChatIds[$userId]))
		{
			return in_array($chatId, $this->memberChatIds[$userId], true);
		}

		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		$relation = \Bitrix\Im\Model\RelationTable::getRow([
			'select' => ['ID'],
			'filter' => ['=CHAT_ID' => $chatId, '=USER_ID' => $userId],
		]);

		return $relation !== null;
	}

	/**
	 * Is the caller a guest user (chat sharing link). Guests reach the journal through the guest REST
	 * whitelist, and their access boundary is the chat link rather than the portal.
	 *
	 * The answer is memoised per user: it is asked for by the enrichment of every row, by the counter and
	 * by the push, while a cold user cache turns each of those into a query of its own. One page of the
	 * journal therefore costs one such query instead of one per row.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isGuestUser(int $userId): bool
	{
		if (isset($this->guestFlags[$userId]))
		{
			return $this->guestFlags[$userId];
		}

		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		$user = \Bitrix\Im\V2\Entity\User\User::getInstance($userId);

		return $this->guestFlags[$userId] = ($user->isExist() && $user->isGuest());
	}

	/**
	 * Warms the guest flag of several users at once.
	 *
	 * A finishing group call replays the journal participant by participant, and asking for the flag inside
	 * that loop costs one cold user load per participant. Asked for together they cost one batch instead:
	 * measured on a cold cache, eight participants take 25 queries this way against 35 one by one.
	 *
	 * @param array<int> $userIds
	 * @return void
	 */
	public function preloadGuestFlags(array $userIds): void
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return;
		}

		$ids = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			fn(int $userId) => $userId > 0 && !isset($this->guestFlags[$userId])
		)));
		if ($ids === [])
		{
			return;
		}

		foreach (\Bitrix\Im\V2\Entity\User\UserFactory::getInstance()->getUsersByIds($ids) as $userId => $user)
		{
			$this->guestFlags[(int)$userId] = $user->isExist() && $user->isGuest();
		}
	}

	/**
	 * Warms whether each of the users is a member of the chat of each of the calls, in one query.
	 *
	 * The roster goes out to the members of the chat alone (see isRosterReader()), and asked row by row the
	 * membership costs a query per row of the page and per person of a batch of events. The answers are kept
	 * until the next warm-up names the same pair again, the way the chats of a guest are kept per page. The query
	 * is the one of CallChatMembership: the search by a participant asks the same predicate by it.
	 *
	 * Every pair is written down as outside the chat before the query runs, so a failed read leaves the rows
	 * with an empty roster and no query of their own; the failure is written to the journal and goes no
	 * further - the roster is a part of the row, not a condition of it.
	 *
	 * A user whose chats are already known whole - a guest on the page, see getGuestChatIds() - is answered
	 * from them and left out. A service whose rows carry no roster warms nothing.
	 *
	 * @param array<int> $callIds
	 * @param array<int> $userIds
	 * @return void
	 */
	public function preloadChatMembership(array $callIds, array $userIds): void
	{
		if (!$this->withParticipants)
		{
			return;
		}

		$callIds = array_values(array_unique(array_filter(
			array_map('intval', $callIds),
			static fn(int $callId) => $callId > 0,
		)));
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			fn(int $userId) => $userId > 0 && !isset($this->memberChatIds[$userId]),
		)));
		if ($callIds === [] || $userIds === [])
		{
			return;
		}

		foreach ($userIds as $userId)
		{
			foreach ($callIds as $callId)
			{
				$this->chatMembershipByCall[$userId][$callId] = false;
			}
		}

		try
		{
			if (!\Bitrix\Main\Loader::includeModule('im'))
			{
				return;
			}

			$rows = CallChatMembership::query($callIds, $userIds)->fetchAll();
		}
		catch (\Throwable $exception)
		{
			Logger::getInstance()->error("Failed to preload chat membership: {$exception->getMessage()}");

			return;
		}

		foreach ($rows as $row)
		{
			$this->chatMembershipByCall[(int)$row['MEMBER_ID']][(int)$row['ID']] = true;
		}
	}

	/**
	 * Warms everything the rows of one read of the journal will be enriched with: the roster of the calls
	 * and the mark of their Copilot outcomes. Both are read per row and both would otherwise cost a query
	 * per row of the page.
	 *
	 * Called on the read rather than on the finished page: the data of a row is built inside the loop that
	 * walks the rows, so a memory filled after the page is assembled is a memory filled too late.
	 *
	 * Only the entries of the chat source carry a call to read either of them for - a telephony entry has
	 * none. A refusal is written down and the page goes on. Either warm-up remembers the ids of its batch
	 * before it queries, so the rows of a refused batch keep the cleared value - no outcomes (false), an empty
	 * roster with a total of nought - without a query of their own until the next read: re-reading them on the
	 * spot would repeat the failed query for every row.
	 *
	 * A consumer that carries no roster warms none either, see $withParticipants: the call picker of the
	 * entity selector draws no faces.
	 *
	 * The unseen mark of the missed rows is warmed only when the owner of the rows is named, and for every
	 * source, telephony included. The formatted rows of the page have it warmed by getList() already, so
	 * getPage() names nobody; a consumer that formats raw rows on its own names their owner.
	 *
	 * @param array $rows Journal rows, formatted or raw.
	 * @param int|null $userId Owner of the rows, when they are raw.
	 * @return void
	 */
	public function preloadRowData(array $rows, ?int $userId = null): void
	{
		if ($userId > 0)
		{
			try
			{
				$this->preloadUnseenFlags($rows, $userId);
			}
			catch (\Throwable $exception)
			{
				Logger::getInstance()->error("Failed to preload call log unseen marks: {$exception->getMessage()}");
			}
		}

		$callIds = $this->callIdsOf($rows);
		if ($callIds === [])
		{
			return;
		}

		if ($this->withParticipants)
		{
			try
			{
				$this->participants->preload($callIds);
			}
			catch (\Throwable $exception)
			{
				Logger::getInstance()->error("Failed to preload call participants: {$exception->getMessage()}");
			}
		}

		// Caught apart from the roster: neither warm-up is a condition of correctness, and a failure of one
		// of them must not cost the page the other.
		try
		{
			$this->preloadFollowUpFlags($callIds);
		}
		catch (\Throwable $exception)
		{
			Logger::getInstance()->error("Failed to preload call follow-up flags: {$exception->getMessage()}");
		}
	}

	/**
	 * Calls the entries of the chat source stand for; a telephony entry stands for none.
	 *
	 * @param array $rows Journal rows, formatted or raw.
	 * @return array<int>
	 */
	private function callIdsOf(array $rows): array
	{
		$callIds = [];
		foreach ($rows as $row)
		{
			$sourceType = $row['sourceType'] ?? $row['SOURCE_TYPE'] ?? '';
			if ($sourceType !== CallUserLogTable::SOURCE_TYPE_CALL)
			{
				continue;
			}

			$callIds[] = (int)($row['sourceCallId'] ?? $row['SOURCE_CALL_ID'] ?? 0);
		}

		return $callIds;
	}

	/**
	 * Warms the mark of the Copilot outcomes of several calls at once.
	 *
	 * The projection reads the call ids alone and is covered by IX_CALL_OUTCOME_CALL, so it never reaches
	 * the rows of the table: what the mark needs is the fact of an outcome, not its content. Reading the
	 * outcomes themselves would cost the page a long text and the properties of every one of them.
	 *
	 * The ids are written down as having no outcomes before the query runs, so a failure of the query leaves
	 * them cleared and it is up to the caller to log it.
	 *
	 * @param array<int> $callIds
	 * @return void
	 */
	private function preloadFollowUpFlags(array $callIds): void
	{
		$unknown = [];
		foreach ($callIds as $callId)
		{
			$callId = (int)$callId;
			if ($callId > 0 && !isset($this->followUpFlags[$callId]))
			{
				$unknown[$callId] = true;
			}
		}
		if ($unknown === [])
		{
			return;
		}

		$unknown = array_keys($unknown);
		foreach ($unknown as $callId)
		{
			$this->followUpFlags[$callId] = false;
		}

		$rows = CallOutcomeTable::query()
			->setSelect(['CALL_ID'])
			->whereIn('CALL_ID', $unknown)
			->whereIn('TYPE', array_column(self::CONTENTFUL_SENSE_TYPES, 'value'))
			->setDistinct()
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$this->followUpFlags[(int)$row['CALL_ID']] = true;
		}
	}

	/**
	 * Remembers that the call has no outcomes without asking the database.
	 *
	 * The live event path fires on a change of the state of the call, which is earlier than the AI part of
	 * the module has written anything at all: the answer there would always be no, and the question would
	 * cost one query per participant of a call that has just finished.
	 *
	 * @param int $callId
	 * @return void
	 */
	private function markNoFollowUp(int $callId): void
	{
		if ($callId > 0)
		{
			$this->followUpFlags[$callId] = false;
		}
	}

	/**
	 * Whether the call has outcomes of a contentful kind.
	 *
	 * Reads on the spot when the warm-up did not name the call. A call named by a failed warm-up counts as
	 * named: it stays cleared (false) and is not read again.
	 *
	 * @param int $callId
	 * @return bool
	 */
	private function hasFollowUp(int $callId): bool
	{
		if ($callId <= 0)
		{
			return false;
		}

		if (!isset($this->followUpFlags[$callId]))
		{
			$this->preloadFollowUpFlags([$callId]);
		}

		return $this->followUpFlags[$callId] ?? false;
	}

	/**
	 * Calculate call duration based on participants who actually joined
	 * Returns duration in seconds if 2+ users joined (FIRST_JOINED is set), otherwise 0
	 *
	 * @param Call $call Call object
	 * @return int Duration in seconds
	 */
	private function getCallDuration(Call $call): int
	{
		$startDate = $call->getStartDate();
		$endDate = $call->getEndDate();

		if (!$startDate instanceof DateTime || !$endDate instanceof DateTime)
		{
			return 0;
		}

		// Get all call participants
		$callUsers = $call->getCallUsers();

		// Count how many users actually joined the call
		$joinedCount = 0;
		foreach ($callUsers as $callUser)
		{
			if ($callUser->getFirstJoined() !== null)
			{
				$joinedCount++;
			}
		}

		// If 2 or more users joined - calculate duration
		if ($joinedCount >= 2)
		{
			$duration = $endDate->getTimestamp() - $startDate->getTimestamp();
			return max(0, $duration);
		}

		return 0;
	}

	/**
	 * Publish the missed counter of a user: the single point every change of it goes out through
	 *
	 * The cache is dropped first because the method may leave with an exception anywhere below, and a reader
	 * has to find an empty cache rather than a number this operation has already made wrong.
	 *
	 * The number is recounted with the chats of a guest read afresh, compared with the badge and stored under
	 * the lock of the badge, the cache included (@see Counter::refresh()). An unchanged number with no seen ids
	 * is not pushed - the client already shows it. Seen ids go out even at the same number - an open list clears
	 * the marks of its rows by them.
	 *
	 * While another process holds the lock, this service counts and compares by itself and writes neither the
	 * badge nor the cache. The holder may have counted before the change this publication answers and store that
	 * earlier number after it, so the last word is left to $onLockBusy: by default to one repeat of the
	 * publication, handed to the dispatcher after the push (@see self::republishCounter()), which leaves it to an
	 * agent in turn if it finds the lock busy again (@see self::republishCounterAgent()).
	 *
	 * @param int $userId
	 * @param array $seenCallIds Journal entries whose unseen mark this operation has just removed
	 * @param (\Closure(int): void)|null $onLockBusy Takes the last word, with the id of the user, from a publication
	 *     that found the lock of the badge busy
	 * @return int The number that went out, or the counted one when nothing was published
	 */
	public function publishCounter(int $userId, array $seenCallIds = [], ?\Closure $onLockBusy = null): int
	{
		Counter::clearCache($userId);

		$counter = Counter::refresh($userId);
		$isLockBusy = $counter === null;
		$counter ??= $this->countAgainstTheBadge($userId);

		if ($counter['isChanged'] || $seenCallIds !== [])
		{
			// Ids go out as strings: that is what the ORM has always given this push, and the app compares them
			// to its own string ids without normalising either side. Handing it integers would silently stop
			// the rows from losing their unseen mark on the other sessions of the same user.
			CallLogPushService::sendCounterUpdate($userId, $counter['count'], array_map('strval', $seenCallIds));
		}

		// Whether or not the number moved: the badge may agree only because the holder has not written yet.
		if ($isLockBusy)
		{
			($onLockBusy ?? self::enqueueCounterRepeat(...))($userId);
		}

		return $counter['count'];
	}

	/**
	 * Repeat the publication of the missed counter of a user that found the lock of the badge busy
	 *
	 * Handed to {@see \Bitrix\Call\Background\JobDispatcher} - must stay public static and accept primitives
	 * only: on a hit it travels through the background queue of the core and runs after the response, in a
	 * console process it runs inline. A fresh service, so that the chats of a guest are read anew. The seen ids
	 * have already gone out with the publication being repeated.
	 *
	 * A repeat hands no repeat of its own to the dispatcher: one that finds the lock busy again arms an agent.
	 *
	 * @param int $userId
	 * @return void
	 */
	public static function republishCounter(int $userId): void
	{
		(new self())->publishCounter($userId, onLockBusy: self::armCounterAgent(...));
	}

	/**
	 * Agent: the publication of the missed counter of a user whose repeat found the lock of the badge busy again
	 *
	 * Stays for as long as the lock is busy, with no limit of runs: until a run counts under the lock, the badge
	 * may keep the earlier number of a holder that counted before the change. The holder cannot keep the lock
	 * past its database session, which ends with its process at the latest.
	 *
	 * @param int $userId
	 * @param int $armedAt Second of arming, which keeps the name apart from agents armed earlier
	 * @return string Its own call while the lock stays busy, so that the runner keeps it; an empty string once the
	 *     number is stored
	 */
	public static function republishCounterAgent(int $userId, int $armedAt): string
	{
		$isLockBusy = false;
		(new self())->publishCounter(
			$userId,
			onLockBusy: static function () use (&$isLockBusy): void {
				$isLockBusy = true;
			},
		);

		return $isLockBusy ? self::getCounterAgentName($userId, $armedAt) : '';
	}

	private static function enqueueCounterRepeat(int $userId): void
	{
		JobDispatcher::getInstance()->enqueue([self::class, 'republishCounter'], [$userId]);
	}

	/**
	 * Leave the last word on the badge of a user to an agent of the module
	 *
	 * AddAgent looks an agent up by its name, running ones included, and joins the arm to the one it finds. The
	 * second of arming in the name and the first run one interval later leave it only agents that have not
	 * counted yet: a running one may have counted before the change, and its empty answer would delete the row
	 * with this arm in it.
	 */
	private static function armCounterAgent(int $userId): void
	{
		$armedAt = time();

		\CAgent::AddAgent(
			self::getCounterAgentName($userId, $armedAt),
			'call',
			'N',
			self::REPUBLISH_AGENT_INTERVAL,
			'',
			'Y',
			\ConvertTimeStamp($armedAt + \CTimeZone::GetOffset() + self::REPUBLISH_AGENT_INTERVAL, 'FULL'),
			existError: false,
		);
	}

	private static function getCounterAgentName(int $userId, int $armedAt): string
	{
		return self::class . "::republishCounterAgent({$userId}, {$armedAt});";
	}

	/**
	 * The number as this service counts it and whether it differs from the badge, for a publication that finds
	 * the lock of the badge held by another process
	 *
	 * Both are read from master: a lagging replica would hand them the same stale number, dropping a publication
	 * that was due.
	 *
	 * @param int $userId
	 * @return array{count: int, isChanged: bool}
	 */
	private function countAgainstTheBadge(int $userId): array
	{
		$pool = Application::getInstance()->getConnectionPool();
		try
		{
			$pool->useMasterOnly(true);

			$count = $this->getMissedCounter($userId);

			return ['count' => $count, 'isChanged' => $count !== Counter::readBadge($userId)];
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * The counter for the answer of a list request: published on the first page without a search, taken from
	 * the cache on every other one
	 *
	 * The number depends neither on the search nor on the page a scroll has reached, and every write of the
	 * journal publishes it anew, so the cache is enough there. The first page is where the section is opened,
	 * and that is where the number is made fresh.
	 *
	 * @param int $userId
	 * @param array $filter Filter parameters of the request (STATUS, TYPE, EXCLUDE_STATUS, SEARCH)
	 * @param int $lastId Last ID for pagination
	 * @return int
	 */
	public function getMissedCounterForPage(int $userId, array $filter = [], int $lastId = 0): int
	{
		// Both are read the way buildQueryFilter() reads them: only a positive cursor makes a page of a scroll,
		// and a search is whatever hasSearch() calls one there.
		if ($lastId <= 0 && !$this->hasSearch($filter))
		{
			return $this->publishCounter($userId);
		}

		return $this->getCachedMissedCounter($userId);
	}

	/**
	 * The counter of a user as the cache holds it, counted on a miss.
	 *
	 * @param int $userId
	 * @return int
	 */
	public function getCachedMissedCounter(int $userId): int
	{
		return Counter::getCount($userId);
	}

	/**
	 * Mark calls as seen
	 *
	 * @param int $userId
	 * @param array $callIds
	 * @return int New counter value
	 */
	public function markAsSeen(int $userId, array $callIds): int
	{
		// The only write the journal opens to its reader, and the ids come straight from the client, so the
		// read cut is applied here too: a guest must not clear - nor be told about, the publication carries
		// the ids back - the missed calls of a chat they no longer belong to.
		$callIds = $this->filterAccessibleLogIds($userId, $callIds);

		if (empty($callIds))
		{
			return $this->getMissedCounter($userId);
		}

		// Delete all counters for specified call IDs
		CallUserLogCountersTable::deleteByFilter([
			'USER_ID' => $userId,
			'USERLOG_ID' => $callIds
		]);

		foreach ($callIds as $callId)
		{
			$this->unseenFlags[$callId] = false;
		}

		return $this->publishCounter($userId, $callIds);
	}

	/**
	 * Mark all calls as seen for user
	 *
	 * @param int $userId
	 * @return int New counter value (should be 0)
	 */
	public function markAllAsSeen(int $userId): int
	{
		// "All" is all the user may see - the ids come narrowed, and markAsSeen() narrows what a client
		// names on its own.
		return $this->markAsSeen($userId, $this->getAccessibleCounterLogIds($userId));
	}

	/**
	 * Delete single call log entry for user
	 *
	 * @param int $userId
	 * @param int $callId Call log ID to delete
	 * @return bool True if deleted, false if not found or not owned by user
	 */
	public function deleteEntry(int $userId, int $callId): bool
	{
		CallUserLogCountersTable::deleteByFilter([
			'USER_ID' => $userId,
			'USERLOG_ID' => $callId
		]);

		// Kept next to the counter rows this method has just removed: the early exits below leave without
		// publishing, and the cache would otherwise keep the number those rows used to give.
		Counter::clearCache($userId);
		unset($this->unseenFlags[$callId]);

		try
		{
			CallUserLogTable::deleteByFilter([
				'ID' => $callId,
				'USER_ID' => $userId
			]);

			$recordExists = CallUserLogTable::getCount([ 'ID' => $callId ]) > 0;
			if ($recordExists)
			{
				return false;
			}

			\Bitrix\Call\Model\CallUserLogIndexTable::deleteByFilter([
				'USERLOG_ID' => $callId
			]);

			$this->publishCounter($userId);

			return true;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}

	/**
	 * Delete all call log entries linked to the given chat.
	 * Triggered by im:OnAfterChatDelete to clean up userlog records for calls whose chat no longer exists.
	 */
	public function deleteByChatId(int $chatId): void
	{
		if ($chatId <= 0)
		{
			return;
		}

		$connection    = Application::getConnection();
		$userlogTable  = CallUserLogTable::getTableName();
		$countersTable = CallUserLogCountersTable::getTableName();
		$indexTable    = CallUserLogIndexTable::getTableName();
		$sessionTable  = CallTable::getTableName();
		$callType      = $connection->getSqlHelper()->forSql(CallUserLogTable::SOURCE_TYPE_CALL);

		$userlogFilter = "WHERE SOURCE_TYPE = '{$callType}' AND SOURCE_CALL_ID IN (SELECT ID FROM {$sessionTable} WHERE CHAT_ID = {$chatId})";

		$affectedUsers = array_column(
			$connection->query("SELECT DISTINCT USER_ID FROM {$userlogTable} {$userlogFilter}")->fetchAll(),
			'USER_ID'
		);
		if (empty($affectedUsers))
		{
			return;
		}

		$userlogIdsSubquery = "SELECT ID FROM {$userlogTable} {$userlogFilter}";

		$connection->startTransaction();
		try
		{
			$connection->queryExecute("DELETE FROM {$countersTable} WHERE USERLOG_ID IN ({$userlogIdsSubquery})");
			$connection->queryExecute("DELETE FROM {$indexTable} WHERE USERLOG_ID IN ({$userlogIdsSubquery})");
			$connection->queryExecute("DELETE FROM {$userlogTable} {$userlogFilter}");
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			Logger::getInstance()->error("Failed to delete call log for chat {$chatId}: {$exception->getMessage()}");

			return;
		}

		foreach ($affectedUsers as $userId)
		{
			$userIdInt = (int)$userId;
			try
			{
				$this->publishCounter($userIdInt);
			}
			catch (\Throwable $exception)
			{
				Logger::getInstance()->error(
					"Failed to send call log counter update for user {$userIdInt} after chat {$chatId} delete: {$exception->getMessage()}"
				);
			}
		}
	}

	protected function getLockName(string $sourceType, int $sourceCallId, int $userId): string
	{
		return "userlog_{$sourceType}_{$sourceCallId}_{$userId}";
	}

	/**
	 * Add new event or update existing one using merge strategy
	 *
	 * @param string $sourceType
	 * @param int $sourceCallId
	 * @param int $userId
	 * @param string $status
	 * @param DateTime|null $statusTime
	 * @param string|null $direction Which side of the call the owner of the row is on, one of the
	 *                               DIRECTION_* of {@see CallUserLogTable}; any other value is read as null.
	 *                               Written once: a row that already has a side keeps it. A row whose side
	 *                               is null or an empty string has none and takes this one.
	 * @return int|null
	 */
	public function addOrUpdateEvent(
		string $sourceType,
		int $sourceCallId,
		int $userId,
		string $status,
		?DateTime $statusTime = null,
		?string $direction = null,
	): ?int
	{
		$knownDirections = [CallUserLogTable::DIRECTION_INCOMING, CallUserLogTable::DIRECTION_OUTGOING];
		if (!in_array($direction, $knownDirections, true))
		{
			$direction = null;
		}

		$logger = Logger::getInstance();

		if (!$statusTime)
		{
			$statusTime = new DateTime();
		}

		$logger->info("Received new userlog event for {$sourceType}/{$sourceCallId}/{$userId}/{$status} at {$statusTime}");

		$lockName = $this->getLockName($sourceType, $sourceCallId, $userId);
		$connection = Application::getConnection();

		if (!$connection->lock($lockName, self::LOCK_TTL))
		{
			$logger->error("Failed to lock {$lockName}");
			return null;
		}
		$logger->notice("Locked userlog event for {$lockName}");

		try
		{
			$existingRecord = CallUserLogTable::getList([
				'filter' => [
					'=SOURCE_TYPE' => $sourceType,
					'=SOURCE_CALL_ID' => $sourceCallId,
					'=USER_ID' => $userId
				],
				'select' => ['ID', 'STATUS', 'DIRECTION'],
				'limit' => 1
			])->fetch();

			$isUpdate = (bool)$existingRecord;
			$oldStatus = $existingRecord ? $existingRecord['STATUS'] : null;

			$fields = [
				'SOURCE_TYPE' => $sourceType,
				'SOURCE_CALL_ID' => $sourceCallId,
				'USER_ID' => $userId,
				'STATUS' => $status,
				'STATUS_TIME' => $statusTime
			];

			// The side of a row is decided once: a new status is not a new side. A field left out of the
			// merge keeps its column as it is, so a row that already has a side is not handed one. An empty
			// side is no side, as the tabs and the pass of the journal read it.
			$hasDirection = $existingRecord && (string)$existingRecord['DIRECTION'] !== '';
			if ($direction !== null && !$hasDirection)
			{
				$fields['DIRECTION'] = $direction;
			}

			$result = CallUserLogTable::addMerge($fields);

			if (!$result->isSuccess())
			{
				$logger->error("Failed to merge data for {$lockName}");
				return null;
			}

			$eventId = $result->getId();

			if ($isUpdate)
			{
				$this->updateCallLogIndex($eventId, $sourceType, $sourceCallId, $userId);
			}
			else
			{
				$this->addCallLogIndex($eventId, $sourceType, $sourceCallId, $userId);
			}

			$callLogData = [
				'id' => $eventId,
				'sourceType' => $sourceType,
				'sourceCallId' => $sourceCallId,
				'userId' => $userId,
				'status' => $status,
				'statusTime' => $statusTime->format(\DateTime::ATOM),
			];

			if ($oldStatus)
			{
				$callLogData['oldStatus'] = $oldStatus;
			}

			// Nothing has been written to b_call_outcome by the time the state of a call changes, so the
			// mark of the row is answered from memory rather than by a query repeated per participant of a
			// call that has just finished. It appears with the next read of the page.
			if ($sourceType === CallUserLogTable::SOURCE_TYPE_CALL)
			{
				$this->markNoFollowUp($sourceCallId);
			}

			$callLogData['callData'] = $this->getCallData($callLogData, $userId);

			// Same policy as the read path: a record the guest may not open is not handed to them. Empty
			// callData means exactly that (see getImCallData), and pushing it anyway would put a nameless row
			// with no dialog into an open journal - a row a tap does nothing with. The counter is still
			// published below: it carries no chat data and the badge must stay right.
			$isPushable = !($callLogData['callData'] === [] && $sourceType === CallUserLogTable::SOURCE_TYPE_CALL
				&& $this->isGuestUser($userId));

			if ($status === CallUserLogTable::STATUS_MISSED && $oldStatus !== CallUserLogTable::STATUS_MISSED)
			{
				$this->addToCounter($eventId, $userId);
			}

			$command = $isUpdate ? 'Update' : 'Add';
			if ($isPushable)
			{
				// The side the row carries after this write: the one it already had, or the one written now. A side
				// this call named but did not write must not reach the client, or the push and the page would part.
				$rowDirection = $hasDirection ? $existingRecord['DIRECTION'] : $direction;
				$callLogData['type'] = $this->resolveType($rowDirection, $status);
				// Built after addToCounter(): the write that makes the row missed makes its counter row as well.
				$callLogData['isUnseen'] = $this->resolveUnseen($status, $eventId, $userId);

				CallLogPushService::sendCallLog($command, $callLogData);
			}
		}
		finally
		{
			$logger->notice("Unlock {$lockName}");
			$connection->unlock($lockName);
		}

		// Outside the lock of the row on purpose: the lock guards the row, while the number is recounted and
		// written under the lock of the badge, and a finished group call runs this method once per participant.
		// The early exits above publish nothing.
		$this->publishCounter($userId);

		return $eventId;
	}

	/**
	 * Add event to missed counter
	 *
	 * @param int $callLogId
	 * @param int $userId
	 * @return void
	 */
	private function addToCounter(int $callLogId, int $userId): void
	{
		try
		{
			CallUserLogCountersTable::add([
				'USERLOG_ID' => $callLogId,
				'USER_ID' => $userId
			]);
		}
		catch (DuplicateEntryException $e)
		{
			// The counter row is already there: the counter has not moved, while the entry is unseen all the same.
			$this->unseenFlags[$callLogId] = true;

			return;
		}

		$this->unseenFlags[$callLogId] = true;
		Counter::clearCache($userId);
	}


	/**
	 * Get CRM entity display name
	 */
	private function getCrmEntityName(string $entityType, int $entityId): ?string
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		$entityTypeNormalized = strtoupper($entityType);
		$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeNormalized);

		if ($entityTypeId === \CCrmOwnerType::Undefined)
		{
			return null;
		}

		// Use CRM Service Container to get entity factory
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return null;
		}

		$field = $entityTypeId === \CCrmOwnerType::Contact
			? \Bitrix\Crm\Item::FIELD_NAME_FULL_NAME
			: \Bitrix\Crm\Item::FIELD_NAME_TITLE
		;
		$entity = $factory->getItem($entityId, [$field]);
		if (!$entity)
		{
			return null;
		}

		// Get formatted title using CRM API
		return $entity->getHeading();
	}

	/**
	 * The search of the journal by chat name, user name, or phone number, as a condition of the query of the page.
	 *
	 * Two sources answer the same expression, each with a branch of the condition, and the branches are joined by
	 * OR in the query itself. The first one reads the search index of the journal - the name of the chat, the
	 * companion, the number; the second goes through the roster of the call to people of that name, past that index
	 * entirely, and answers only for calls whose chat the owner is in (see ParticipantSearch). A row both find is one
	 * row of the query.
	 *
	 * One page may ask for the same search several times - getPage() refills a page the guest cut has thinned
	 * out - so the condition is remembered by owner and expression, see self::$searchConditions.
	 *
	 * @param string $search Search query
	 * @param int $userId Current user ID
	 * @return JournalSearchCondition|null Null when the search answers nothing: no word for the expression, or
	 *     neither source has a branch
	 */
	private function searchConditionOf(string $search, int $userId): ?JournalSearchCondition
	{
		$expression = $this->searchExpression->build($search);
		if ($expression === '')
		{
			return null;
		}

		if (!array_key_exists($expression, $this->searchConditions[$userId] ?? []))
		{
			$byIndex = $this->journalIndexSearch->conditionOf($search, $expression, $userId);

			// A query of digits alone is no name, and the directory would answer it by the id of an account -
			// see SearchExpression::mayCarryAName(). A phone number is exactly such a query for this list.
			$byParticipants = null;
			if ($this->searchExpression->mayCarryAName($search))
			{
				$byParticipants = $this->participantSearch->conditionOf($expression, $userId);
			}

			$this->searchConditions[$userId][$expression] = JournalSearchCondition::anyOf($byIndex, $byParticipants);
		}

		return $this->searchConditions[$userId][$expression];
	}

	private function addCallLogIndex(int $callLogId, string $sourceType, int $sourceCallId, int $userId): void
	{
		$indexData = $this->prepareIndexData($sourceType, $sourceCallId, $userId);
		if ($indexData === null)
		{
			return;
		}

		$index = \Bitrix\Call\Internals\CallLogIndex::create()
			->setCallLogId($callLogId)
			->setOwnerId($userId)
			->setTitle($indexData['title'])
			->setUserNames($indexData['userNames'])
			->setPhoneNumbers($indexData['phoneNumbers']);

		CallUserLogTable::addIndexRecord($index);
	}

	private function updateCallLogIndex(int $callLogId, string $sourceType, int $sourceCallId, int $userId): void
	{
		$indexData = $this->prepareIndexData($sourceType, $sourceCallId, $userId);
		if ($indexData === null)
		{
			return;
		}

		$index = \Bitrix\Call\Internals\CallLogIndex::create()
			->setCallLogId($callLogId)
			->setOwnerId($userId)
			->setTitle($indexData['title'])
			->setUserNames($indexData['userNames'])
			->setPhoneNumbers($indexData['phoneNumbers']);

		CallUserLogTable::updateIndexRecord($index);
	}

	private function prepareIndexData(string $sourceType, int $sourceCallId, int $userId): ?array
	{
		$title = '';
		$userNames = [];
		$phoneNumbers = [];

		if ($sourceType === CallUserLogTable::SOURCE_TYPE_VOXIMPLANT)
		{
			if (!\Bitrix\Main\Loader::includeModule('voximplant'))
			{
				return null;
			}

			$statisticRecord = \Bitrix\Voximplant\StatisticTable::getById($sourceCallId)->fetch();
			if (!$statisticRecord)
			{
				return null;
			}

			if (!empty($statisticRecord['PHONE_NUMBER']))
			{
				$phoneNumbers[] = $statisticRecord['PHONE_NUMBER'];
			}

			$isInternalCall = $this->isInternalVoximplantCall($sourceCallId);
			if ($isInternalCall)
			{
				$otherParticipant = $this->getOtherVoximplantParticipant($sourceCallId, $userId);
				if ($otherParticipant && !empty($otherParticipant['displayName']))
				{
					$userNames[] = $otherParticipant['displayName'];
					$title = $otherParticipant['displayName'];
				}
			}
			else
			{
				if ($statisticRecord['CRM_ENTITY_TYPE'] && $statisticRecord['CRM_ENTITY_ID'])
				{
					$displayName = $this->getCrmEntityName(
						$statisticRecord['CRM_ENTITY_TYPE'],
						$statisticRecord['CRM_ENTITY_ID']
					);
					if ($displayName)
					{
						$title = $displayName;
						$userNames[] = $displayName;
					}
				}
			}
		}
		elseif ($sourceType === CallUserLogTable::SOURCE_TYPE_CALL)
		{
			if (!\Bitrix\Main\Loader::includeModule('im'))
			{
				return null;
			}

			$callObject = Call::loadWithId($sourceCallId);
			if (!$callObject)
			{
				return null;
			}

			$chatEntity = $callObject->getAssociatedEntity();
			if (!$chatEntity)
			{
				return null;
			}

			$chatName = $chatEntity->getName($userId);
			if ($chatName)
			{
				$title = $chatName;
			}

			if ($chatEntity->isPrivateChat())
			{
				$otherUserId = (int)$chatEntity->getEntityId($userId);
				$user = \CUser::GetByID($otherUserId)->Fetch();
				if ($user)
				{
					$userName = \CUser::FormatName(
						\CSite::GetNameFormat(false),
						$user,
						true,
						false
					);
					if ($userName)
					{
						$userNames[] = $userName;
						if (empty($title))
						{
							$title = $userName;
						}
					}
				}
			}
		}

		return [
			'title' => $title,
			'userNames' => $userNames,
			'phoneNumbers' => $phoneNumbers,
		];
	}

}
