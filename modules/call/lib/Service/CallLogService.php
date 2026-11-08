<?php

namespace Bitrix\Call\Service;

use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Type\DateTime;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Model\CallUserLogCountersTable;
use Bitrix\Call\Model\CallUserLogIndexTable;
use Bitrix\Call\Counter;
use Bitrix\Call\Call;
use Bitrix\Main\ORM\Fields\Relations\Reference;
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
	 * Chats of a user, by user id, for the lifetime of this service. Filled once per page and reused by the
	 * enrichment of every row on it - see {@see self::isChatMember()}.
	 *
	 * @var array<int, array<int>>
	 */
	private array $memberChatIds = [];

	/**
	 * Guest flag of a user, by user id, for the lifetime of this service. The flag decides the scope of both
	 * halves of the log, so it is asked for on every row, every counter and every push - and it changes only
	 * with the user themselves, never within one operation.
	 *
	 * @var array<int, bool>
	 */
	private array $guestFlags = [];

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
	 * @return array Formatted rows, each with its own 'callData'.
	 */
	public function getPage(int $userId, array $filter = [], int $lastId = 0, int $limit = self::DEFAULT_LIMIT): array
	{
		$limit = self::normalizeLimit($limit);
		// Only a guest is narrowed to what they may open, so only their page can come out short.
		$dropInaccessible = $this->isGuestUser($userId);

		$page = [];
		$cursor = $lastId;
		for ($read = 0; $read < self::MAX_PAGE_READS; $read++)
		{
			$rows = $this->getList($userId, $filter, $cursor, $limit);
			if ($rows === [])
			{
				break;
			}

			// The cursor the client itself would send next: the id of the LAST row read, not the smallest one,
			// so that the refill walks the journal exactly the way paging walks it.
			$cursor = (int)$rows[array_key_last($rows)]['id'];

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
	 * @return array
	 */
	public function getList(int $userId, array $filter = [], int $lastId = 0, int $limit = self::DEFAULT_LIMIT): array
	{
		// Build query filter
		$queryFilter = $this->buildQueryFilter($userId, $filter, $lastId);

		// If search returned no results, return empty array
		if ($queryFilter === null)
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

		$query = CallUserLogTable::query()
			->setSelect(['*'])
			->setFilter($queryFilter)
			->setOrder(['STATUS_TIME' => 'DESC', 'ID' => 'DESC'])
			->setLimit(self::normalizeLimit($limit))
		;

		if ($guestChatIds !== null)
		{
			$this->restrictToChats($query, $guestChatIds);
		}

		$result = [];
		$dbResult = $query->exec();

		while ($row = $dbResult->fetch())
		{
			$result[] = $this->formatCallLog($row, $userId);
		}

		return $result;
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
	 * @param int $userId
	 * @param array $filter Filter parameters (STATUS, TYPE, SEARCH)
	 * @param int $lastId Last ID for pagination
	 * @return array|null Filter array or null if search returned no results
	 */
	public function buildQueryFilter(int $userId, array $filter = [], int $lastId = 0): ?array
	{
		$queryFilter = ['USER_ID' => $userId];

		// Pagination
		if ($lastId > 0)
		{
			$queryFilter['<ID'] = $lastId;
		}

		// Status filter
		if (!empty($filter['STATUS']))
		{
			$queryFilter['STATUS'] = $filter['STATUS'];
		}

		// Type filter (incoming/outgoing)
		if (!empty($filter['TYPE']) && empty($queryFilter['STATUS']))
		{
			$type = strtolower($filter['TYPE']);
			if ($type === 'incoming')
			{
				$queryFilter['STATUS'] = [
					CallUserLogTable::STATUS_MISSED,
					CallUserLogTable::STATUS_DECLINED,
					CallUserLogTable::STATUS_ANSWERED
				];
			}
			elseif ($type === 'outgoing')
			{
				$queryFilter['STATUS'] = CallUserLogTable::STATUS_INITIATED;
			}
		}

		// Search filter
		if (!empty($filter['SEARCH']))
		{
			$searchIds = $this->searchCallLogs(trim($filter['SEARCH']), $userId);
			if (!empty($searchIds))
			{
				$queryFilter['@ID'] = $searchIds;
			}
			else
			{
				// If nothing found, return null to indicate empty result
				return null;
			}
		}

		return $queryFilter;
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
		$isUnseen = false;
		if ($row['STATUS'] === CallUserLogTable::STATUS_MISSED)
		{
			$isUnseen = $this->isUnseen((int)$row['ID'], $userId);
		}

		$type = $row['STATUS'] === CallUserLogTable::STATUS_INITIATED ? 'outgoing' : 'incoming';

		return [
			'id' => (int)$row['ID'],
			'sourceType' => $row['SOURCE_TYPE'],
			'sourceCallId' => (int)$row['SOURCE_CALL_ID'],
			'status' => $row['STATUS'],
			'statusTime' => $row['STATUS_TIME'] instanceof DateTime
				? $row['STATUS_TIME']->format(\DateTime::ATOM)
				: $row['STATUS_TIME'],
			'type' => $type,
			'isUnseen' => $isUnseen
		];
	}

	/**
	 * Check if call log entry is unseen
	 *
	 * @param int $callLogId
	 * @param int $userId
	 * @return bool
	 */
	private function isUnseen(int $callLogId, int $userId): bool
	{
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
		// Employees are deliberately left as they are: the same gap exists for them and predates this method
		// being opened to guests, so narrowing it for everyone belongs to its own task, not to this one.
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

		return $result;
	}

	/**
	 * Is the user still a member of the chat the call belongs to.
	 *
	 * Membership is read straight from b_im_relation rather than through Chat::checkAccess(): that method
	 * answers differently across core versions - an object on the newer ones, a plain bool on the older -
	 * and on the older one it stays true for a user who has already been removed from the chat, which is
	 * exactly the case this guard exists for. The relation row is the ground truth on every version.
	 *
	 * The answer is memoised per user for the lifetime of the service: a page of the journal holds many
	 * calls of the same chat, and asking the database once per row would put a query per row into the main
	 * guest scenario. getList() fills the same cache in one query before the page is even cut.
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

		// Filled by getList() for the page being enriched, and only for it: a page holds many calls of the
		// same chat, so asking per row would put a query per row into the main guest scenario. Outside a page
		// there is nothing to reuse and nothing to go stale - the membership is asked for on the spot.
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
	 * Mark calls as seen
	 *
	 * @param int $userId
	 * @param array $callIds
	 * @return int New counter value
	 */
	public function markAsSeen(int $userId, array $callIds): int
	{
		// The only write the journal opens to its reader, and the ids come straight from the client, so the
		// read cut is applied here too: a guest must not clear - nor be told about, the push below carries
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

		Counter::clearCache($userId);

		$newCounterValue = $this->getMissedCounter($userId);

		// Ids go out as strings: that is what the ORM has always given this push, and the app compares them
		// to its own string ids without normalising either side. Handing it integers would silently stop
		// the rows from losing their unseen mark on the other sessions of the same user.
		CallLogPushService::sendCounterUpdate($userId, $newCounterValue, array_map('strval', $callIds));

		return $newCounterValue;
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
		$oldCounter = $this->getMissedCounter($userId);

		CallUserLogCountersTable::deleteByFilter([
			'USER_ID' => $userId,
			'USERLOG_ID' => $callId
		]);

		Counter::clearCache($userId);

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

			$newCounter = $this->getMissedCounter($userId);
			if ($newCounter !== $oldCounter)
			{
				CallLogPushService::sendCounterUpdate($userId, $newCounter);
			}

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
				Counter::clearCache($userIdInt);
				CallLogPushService::sendCounterUpdate($userIdInt, $this->getMissedCounter($userIdInt));
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
	 * @return int|null
	 */
	public function addOrUpdateEvent(string $sourceType, int $sourceCallId, int $userId, string $status, ?DateTime $statusTime = null): ?int
	{
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
				'select' => ['ID', 'STATUS'],
				'limit' => 1
			])->fetch();

			$isUpdate = (bool)$existingRecord;
			$oldStatus = $existingRecord ? $existingRecord['STATUS'] : null;

			$result = CallUserLogTable::addMerge([
				'SOURCE_TYPE' => $sourceType,
				'SOURCE_CALL_ID' => $sourceCallId,
				'USER_ID' => $userId,
				'STATUS' => $status,
				'STATUS_TIME' => $statusTime
			]);

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

			$callLogData['callData'] = $this->getCallData($callLogData, $userId);

			// Same policy as the read path: a record the guest may not open is not handed to them. Empty
			// callData means exactly that (see getImCallData), and pushing it anyway would put a nameless row
			// with no dialog into an open journal - a row a tap does nothing with. The counter update below
			// still goes out: it carries no chat data and the badge must stay right.
			$isPushable = !($callLogData['callData'] === [] && $sourceType === CallUserLogTable::SOURCE_TYPE_CALL
				&& $this->isGuestUser($userId));

			if ($status === CallUserLogTable::STATUS_MISSED && $oldStatus !== CallUserLogTable::STATUS_MISSED)
			{
				$this->addToCounter($eventId, $userId);
			}

			$command = $isUpdate ? 'Update' : 'Add';
			if ($isPushable)
			{
				CallLogPushService::sendCallLog($command, $callLogData);
			}
			CallLogPushService::sendCounterUpdate($userId, $this->getMissedCounter($userId));

			return $eventId;
		}
		finally
		{
			$logger->notice("Unlock {$lockName}");
			$connection->unlock($lockName);
		}
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
			// Record already exists, ignore duplicate entry error
			return;
		}

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
	 * Search in call logs by chat name, user name, or phone number using FTI
	 *
	 * @param string $search Search query
	 * @param int $userId Current user ID
	 * @return array Array of found call log IDs
	 */
	private function searchCallLogs(string $search, int $userId): array
	{
		if (mb_strlen($search) < \Bitrix\Main\ORM\Query\Filter\Helper::getMinTokenSize())
		{
			return [];
		}

		$query = \Bitrix\Call\Model\CallUserLogTable::query();
		$query
			->addSelect('ID')
			->where('USER_ID', $userId);

		$query->registerRuntimeField(
			'CALL_LOG_INDEX',
			new Reference(
				'CALL_LOG_INDEX',
				\Bitrix\Call\Model\CallUserLogIndexTable::class,
				Join::on('this.ID', 'ref.USERLOG_ID'),
				['join_type' => Join::TYPE_INNER]
			)
		);

		$searchText = \Bitrix\Main\ORM\Query\Filter\Helper::matchAgainstWildcard(
			\Bitrix\Main\Search\Content::prepareStringToken($search)
		);
		$query->whereMatch('CALL_LOG_INDEX.SEARCH_CONTENT', $searchText);

		$ids = [];
		foreach ($query->exec() as $row)
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
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
