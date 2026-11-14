<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\Im\Model\RecentTable;
use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenLines\Model\ConfigTable;
use Bitrix\ImOpenLines\Model\QueueTable;
use Bitrix\ImOpenLines\Model\SessionCheckTable;
use Bitrix\ImOpenLines\Model\UserOptionTable;
use Bitrix\ImOpenLines\Queue;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\V2\Statistics\Dto\OperatorLoadItem;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;

Loc::loadMessages(__FILE__);

/**
 * Paginated, filterable status/load snapshot of imopenlines queue operators for
 * imopenlines.v2.Operator.list.
 *
 * Rows come from QueueTable (which line each operator is assigned to) enriched with:
 * - ConfigTable.MAX_CHAT / TYPE_MAX_CHAT (per line chat limit and the metric used to
 *   count "busy" sessions against it, 0/null = fall back to the module-wide
 *   {@see Queue::MAX_CHAT} cap and to the global recent-based count, same convention
 *   as {@see Queue::getCountFreeSlotsOperators()});
 * - UserOptionTable.PAUSE (global per-user boolean, not per-line);
 * - free slots per (line, operator), reproducing the same line + TYPE_MAX_CHAT
 *   semantics as {@see Queue::getCountFreeSlotsOperators()} (an operator's load on
 *   one line never bleeds into another line's freeSlots) but batched across every
 *   line in the page via {@see self::loadFreeSlotsByConfig()} instead of calling
 *   that method once per line, so the query count does not grow with the number of
 *   lines in the result.
 *
 * `status` is derived, not stored: `pause` wins if UserOption.PAUSE = 'Y' for the
 * operator (pause is global, so it applies to all of that operator's queue rows
 * identically); otherwise online/offline mirrors the same decision as
 * {@see Queue::isOperatorOnline()} (im module presence when available, else
 * CUser::IsOnLine() with {@see Queue::getTimeLastActivityOperator()} threshold — the
 * same online/offline decision the rest of the module already uses for routing), but
 * resolved for all requested operators via {@see self::loadOnlineStatusByUser()} in a
 * single batched query instead of one call per operator.
 *
 * Because `status` is a derived value (not a column), the status filter is applied
 * in PHP after loading all queue rows matching the other filters, then the page is
 * sliced. This is bounded by the number of queue rows (operators per line), which is
 * expected to be orders of magnitude smaller than the sessions table, unlike
 * SessionListService's filters which stay at the DB level.
 */
final class OperatorListService
{
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 200;

	/**
	 * Hard offset cap for the PHP-pagination path (when a derived status/hasFreeSlots
	 * filter forces the whole matching set to be materialized and sliced in PHP). The
	 * no-derived-filter fast path pushes limit/offset straight into the DB and is not
	 * bound by this - review round 8 perf item.
	 */
	private const MAX_OFFSET = 10000;

	public const STATUS_ONLINE = OperatorLoadItem::STATUS_ONLINE;
	public const STATUS_OFFLINE = OperatorLoadItem::STATUS_OFFLINE;
	public const STATUS_PAUSE = OperatorLoadItem::STATUS_PAUSE;

	public const ERROR_INVALID_FILTER = 'INVALID_FILTER';
	public const ERROR_OFFSET_TOO_LARGE = 'OFFSET_TOO_LARGE';

	private const VALID_STATUSES = [
		self::STATUS_ONLINE,
		self::STATUS_OFFLINE,
		self::STATUS_PAUSE,
	];

	public function getList(int $userId, array $filter, int $offset, int $limit): Result
	{
		$result = new Result();

		$accessResult = AccessGate::checkReportAccess();
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		if (isset($filter['status']) && !in_array($filter['status'], self::VALID_STATUSES, true))
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_OPERATOR_LIST_INVALID_STATUS_FILTER'), self::ERROR_INVALID_FILTER));

			return $result;
		}

		$filter = $this->normalizeListFilters($filter, $result);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$limit = $this->normalizeLimit($limit);
		$offset = max(0, $offset);

		$hasFreeSlotsFilter = $this->normalizeHasFreeSlotsFilter($filter['hasFreeSlots'] ?? null);
		// status and hasFreeSlots are derived (not stored) values, computed in PHP after
		// enrichment. When one is requested the whole matching set must be materialized and
		// paginated in PHP, so the offset is capped to bound that work. Without a derived
		// filter the page composition does not depend on the computed fields, so limit/offset
		// are pushed straight into the DB query (fast path below), and no cap is needed.
		$hasDerivedFilters = isset($filter['status']) || $hasFreeSlotsFilter !== null;

		if ($hasDerivedFilters && $offset > self::MAX_OFFSET)
		{
			$result->addError(new Error(Loc::getMessage('IMOL_STAT_OPERATOR_LIST_OFFSET_TOO_LARGE'), self::ERROR_OFFSET_TOO_LARGE));

			return $result;
		}

		$allowedOperatorIds = AllowedOperators::getAllowedOperatorIds($userId);
		if (is_array($allowedOperatorIds) && empty($allowedOperatorIds))
		{
			// No visibility at all: empty list, not an error.
			$result->setData([
				'operators' => [],
				'hasNextPage' => false,
			]);

			return $result;
		}

		if ($hasDerivedFilters)
		{
			// PHP-pagination path: a derived filter can drop rows, so every matching row must
			// be enriched and filtered before the page can be sliced.
			$items = $this->buildItems(
				$this->loadQueueRows($filter, $allowedOperatorIds),
				$filter['status'] ?? null,
				$hasFreeSlotsFilter,
			);
			$hasNextPage = count($items) > ($offset + $limit);
			$page = array_slice($items, $offset, $limit);
		}
		else
		{
			// Fast path: no derived filter, so DB pagination is exact. Fetch only the
			// requested page (+1 row to detect a next page) and enrich just that page,
			// instead of materializing every line-operator assignment.
			$items = $this->buildItems(
				$this->loadQueueRows($filter, $allowedOperatorIds, $limit + 1, $offset),
				null,
				null,
			);
			$hasNextPage = count($items) > $limit;
			$page = array_slice($items, 0, $limit);
		}

		$result->setData([
			'operators' => array_map(static fn(OperatorLoadItem $item) => $item->toRestFormat(), $page),
			'hasNextPage' => $hasNextPage,
		]);

		return $result;
	}

	/**
	 * Enriches the given queue rows with per-line/per-operator load, global pause and
	 * online status, builds the OperatorLoadItem list and applies the derived
	 * status/hasFreeSlots filters ($statusFilter/$hasFreeSlotsFilter null = not requested).
	 * All enrichment is batched over exactly the rows passed in, so on the fast path only
	 * the requested page is enriched, not the whole queue.
	 *
	 * @return OperatorLoadItem[]
	 */
	private function buildItems(array $queueRows, ?string $statusFilter, ?bool $hasFreeSlotsFilter): array
	{
		if (empty($queueRows))
		{
			return [];
		}

		$userIds = array_values(array_unique(array_map(static fn(array $row) => (int)$row['USER_ID'], $queueRows)));
		$configIds = array_values(array_unique(array_map(static fn(array $row) => (int)$row['CONFIG_ID'], $queueRows)));

		$maxChatByConfig = $this->loadMaxChatByConfig($configIds);
		$pauseByUser = $this->loadPauseByUser($userIds);
		$freeSlotsByConfig = $this->loadFreeSlotsByConfig($queueRows, $maxChatByConfig);
		$onlineByUser = $this->loadOnlineStatusByUser($userIds);

		$items = [];
		foreach ($queueRows as $row)
		{
			$operatorId = (int)$row['USER_ID'];
			$configId = (int)$row['CONFIG_ID'];
			$maxChatRaw = $maxChatByConfig[$configId]['MAX_CHAT'] ?? 0;
			$maxChat = ($maxChatRaw > 0 && $maxChatRaw <= Queue::MAX_CHAT) ? $maxChatRaw : Queue::MAX_CHAT;
			$freeSlots = $freeSlotsByConfig[$configId][$operatorId] ?? $maxChat;
			// The recent-based branch of the free-slots calculation counts against the
			// global Queue::MAX_CHAT cap, while $maxChat here is already clamped to the
			// line's own limit; when the line limit is smaller than the global cap this
			// can make $freeSlots > $maxChat (floor at 0) or, on an overloaded line, negative
			// (which would push activeSessions above maxChat). Clamp to [0, maxChat] so the
			// reported load stays consistent with the line limit.
			$activeSessions = max(0, min($maxChat, $maxChat - $freeSlots));

			$status = $this->resolveStatus($operatorId, $pauseByUser[$operatorId] ?? false, $onlineByUser[$operatorId] ?? false);

			if ($statusFilter !== null && $status !== $statusFilter)
			{
				continue;
			}

			if ($hasFreeSlotsFilter !== null && (max(0, $freeSlots) > 0) !== $hasFreeSlotsFilter)
			{
				continue;
			}

			$items[] = new OperatorLoadItem(
				$operatorId,
				$configId,
				$status,
				$activeSessions,
				$maxChat,
				$row['LAST_ACTIVITY_DATE'] ?? null,
			);
		}

		return $items;
	}

	/**
	 * Resolves the derived operator status.
	 *
	 * Pause is global per user (UserOptionTable.PAUSE), so it takes priority over the
	 * online/offline check regardless of which line's queue row is being evaluated.
	 * The online flag is precomputed once per unique userId by
	 * {@see self::loadOnlineStatusByUser()} rather than calling
	 * {@see Queue::isOperatorOnline()} again here, to avoid a repeated call per queue
	 * row when the same operator is on several lines.
	 */
	private function resolveStatus(int $userId, bool $isPaused, bool $isOnline): string
	{
		if ($isPaused)
		{
			return self::STATUS_PAUSE;
		}

		return $isOnline ? self::STATUS_ONLINE : self::STATUS_OFFLINE;
	}

	/**
	 * @return array<int, bool> Online flag keyed by USER_ID.
	 *
	 * Mirrors the same online/offline decision as {@see Queue::isOperatorOnline()}
	 * (bots/connectors are always "online"; real operators are online per the im module's
	 * presence, or CUser::IsOnLine() when im is unavailable), but resolves every requested
	 * userId in a single batched query. The bot/connector classification is derived from
	 * the same EXTERNAL_AUTH_ID that {@see Queue::isRealOperator()} keys off (bot = im's
	 * Bot::EXTERNAL_AUTH_ID, connector = 'imconnector') and read in that same query,
	 * instead of calling Queue::isRealOperator() (and its per-id UserV2::getInstance())
	 * once per operator - which was a SELECT per unique userId on a cold cache.
	 */
	private function loadOnlineStatusByUser(array $userIds): array
	{
		$map = [];

		if (empty($userIds))
		{
			return $map;
		}

		if (!Loader::includeModule('im'))
		{
			// Without im there is no bot/connector distinction (Queue::isRealOperator() is
			// true for everyone) and no im presence; CUser::IsOnLine() reads the already-
			// loaded user record, so a per-user call here is not a query per user.
			foreach ($userIds as $userId)
			{
				$map[(int)$userId] = (bool)\CUser::IsOnLine($userId, Queue::getTimeLastActivityOperator());
			}

			return $map;
		}

		$lastActivityDate = Queue::getTimeLastActivityOperator();
		$timeHelper = Application::getConnection()->getSqlHelper()->addSecondsToDateTime('(-' . $lastActivityDate . ')');

		$query = new Query(UserTable::getEntity());
		$query->setSelect(['ID', 'EXTERNAL_AUTH_ID', 'IS_ONLINE_CUSTOM']);
		$query->registerRuntimeField('', new ReferenceField(
			'IM_STATUS',
			'\Bitrix\Im\Model\StatusTable',
			['=ref.USER_ID' => 'this.ID'],
			['join_type' => 'left']
		));
		$query->registerRuntimeField('', new ExpressionField(
			'IS_ONLINE_CUSTOM',
			'CASE WHEN %1$s > ' . $timeHelper . ' AND (%2$s IS NULL OR %1$s > %2$s) THEN \'Y\' ELSE \'N\' END',
			['LAST_ACTIVITY_DATE', 'IM_STATUS.IDLE']
		));
		$query->setFilter(['=ID' => $userIds]);

		$rows = $query->exec()->fetchAll();

		// EXTERNAL_AUTH_ID values Queue::isRealOperator() treats as bot/connector (always
		// "online" per Queue::isOperatorOnline()); anyone else is a real operator whose
		// online flag comes from the im-presence expression above.
		$nonRealAuthIds = [\Bitrix\Im\Bot::EXTERNAL_AUTH_ID, 'imconnector'];

		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if (in_array((string)($row['EXTERNAL_AUTH_ID'] ?? ''), $nonRealAuthIds, true))
			{
				$map[$id] = true;
				continue;
			}

			$map[$id] = ($row['IS_ONLINE_CUSTOM'] ?? 'N') === 'Y';
		}

		// Any requested id without a user row (e.g. deleted) is treated as offline.
		foreach ($userIds as $userId)
		{
			$map[(int)$userId] ??= false;
		}

		return $map;
	}

	private function loadQueueRows(array $filter, ?array $allowedOperatorIds = null, ?int $limit = null, ?int $offset = null): array
	{
		$ormFilter = [];

		if (isset($filter['configId']))
		{
			$ormFilter['=CONFIG_ID'] = $filter['configId'];
		}

		if (isset($filter['userId']))
		{
			$ormFilter['=USER_ID'] = $filter['userId'];
		}

		if (is_array($allowedOperatorIds))
		{
			$existing = $ormFilter['=USER_ID'] ?? null;
			$userIds = $existing === null
				? $allowedOperatorIds
				: array_values(array_intersect((array)$existing, $allowedOperatorIds));

			if (empty($userIds))
			{
				// Requested userId is outside the caller's allowed operators: no queue rows
				// can match, so skip the DB round-trip and return an empty set instead of
				// issuing a `=USER_ID IN ()` query.
				return [];
			}

			$ormFilter['=USER_ID'] = $userIds;
		}

		$params = [
			'select' => ['CONFIG_ID', 'USER_ID', 'LAST_ACTIVITY_DATE'],
			'filter' => $ormFilter,
			// (CONFIG_ID, USER_ID) is unique per queue row, so this order is a stable total
			// order - required for deterministic offset pagination on the fast path.
			'order' => ['CONFIG_ID' => 'ASC', 'USER_ID' => 'ASC'],
		];

		if ($limit !== null)
		{
			$params['limit'] = $limit;
			$params['offset'] = $offset ?? 0;
		}

		return QueueTable::getList($params)->fetchAll();
	}

	/**
	 * @return array<int, array{MAX_CHAT: int, TYPE_MAX_CHAT: ?string}> Keyed by CONFIG_ID.
	 */
	private function loadMaxChatByConfig(array $configIds): array
	{
		if (empty($configIds))
		{
			return [];
		}

		$rows = ConfigTable::getList([
			'select' => ['ID', 'MAX_CHAT', 'TYPE_MAX_CHAT'],
			'filter' => ['=ID' => $configIds],
		])->fetchAll();

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['ID']] = [
				'MAX_CHAT' => (int)$row['MAX_CHAT'],
				'TYPE_MAX_CHAT' => $row['TYPE_MAX_CHAT'] !== '' ? $row['TYPE_MAX_CHAT'] : null,
			];
		}

		return $map;
	}

	/**
	 * @return array<int, bool> Pause flag keyed by USER_ID.
	 */
	private function loadPauseByUser(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = UserOptionTable::getList([
			'select' => ['USER_ID', 'PAUSE'],
			'filter' => ['=USER_ID' => $userIds],
		])->fetchAll();

		$map = [];
		foreach ($rows as $row)
		{
			$map[(int)$row['USER_ID']] = ($row['PAUSE'] ?? 'N') === 'Y';
		}

		return $map;
	}

	/**
	 * @param array<int, array{MAX_CHAT: int, TYPE_MAX_CHAT: ?string}> $maxChatByConfig
	 * @return array<int, array<int, int>> Free slots keyed by CONFIG_ID, then by USER_ID.
	 *
	 * Reproduces {@see Queue::getCountFreeSlotsOperators()}'s two-part calculation
	 * (global recent-based count, then an optional per-line TYPE_MAX_CHAT session_check
	 * override) but batched across every line in this page instead of calling that
	 * method once per CONFIG_ID:
	 * - The recent-based part is not line-scoped in the original method either (it
	 *   filters `b_im_recent` by USER_ID only), so it is computed once for the union
	 *   of every requested operator via {@see self::loadGlobalFreeSlotsByUser()}.
	 * - The TYPE_MAX_CHAT override queries `b_imopenlines_session_check` grouped by
	 *   (CONFIG_ID, OPERATOR_ID) once per distinct stop-status bucket (at most 3,
	 *   see {@see self::resolveStopStatus()}) via {@see self::loadBusyCountsByStopStatus()},
	 *   instead of once per line.
	 * Total query count is therefore bounded (1 + up to 3), independent of how many
	 * lines are in the page, while producing the same per-(line, operator) freeSlots
	 * values as the original per-line calls.
	 */
	private function loadFreeSlotsByConfig(array $queueRows, array $maxChatByConfig): array
	{
		$userIdsByConfig = [];
		foreach ($queueRows as $row)
		{
			$configId = (int)$row['CONFIG_ID'];
			$userIdsByConfig[$configId][] = (int)$row['USER_ID'];
		}

		$allUserIds = [];
		foreach ($userIdsByConfig as $configId => $userIds)
		{
			$userIdsByConfig[$configId] = array_values(array_unique($userIds));
			foreach ($userIdsByConfig[$configId] as $userId)
			{
				$allUserIds[$userId] = true;
			}
		}
		$allUserIds = array_keys($allUserIds);

		$globalFreeSlotsByUser = $this->loadGlobalFreeSlotsByUser($allUserIds);

		$configIdsByStopStatus = [];
		foreach ($userIdsByConfig as $configId => $userIds)
		{
			$configInfo = $maxChatByConfig[$configId] ?? ['MAX_CHAT' => 0, 'TYPE_MAX_CHAT' => null];
			$stopStatus = $this->resolveStopStatus($configInfo['TYPE_MAX_CHAT']);

			if ($stopStatus !== null)
			{
				$configIdsByStopStatus[$stopStatus][] = $configId;
			}
		}

		$busyCountsByConfigAndUser = $this->loadBusyCountsByStopStatus($configIdsByStopStatus, $userIdsByConfig);

		$freeSlotsByConfig = [];
		foreach ($userIdsByConfig as $configId => $userIds)
		{
			$configInfo = $maxChatByConfig[$configId] ?? ['MAX_CHAT' => 0, 'TYPE_MAX_CHAT' => null];
			$maxChatRaw = (int)$configInfo['MAX_CHAT'];
			$maxChat = ($maxChatRaw > 0 && $maxChatRaw <= Queue::MAX_CHAT) ? $maxChatRaw : Queue::MAX_CHAT;

			foreach ($userIds as $userId)
			{
				$freeSlots = $globalFreeSlotsByUser[$userId] ?? Queue::MAX_CHAT;

				$busyCount = $busyCountsByConfigAndUser[$configId][$userId] ?? null;
				if ($busyCount !== null)
				{
					$freeSlots = min($freeSlots, $maxChat - $busyCount);
				}

				$freeSlotsByConfig[$configId][$userId] = $freeSlots;
			}
		}

		return $freeSlotsByConfig;
	}

	/**
	 * @return array<int, int> Free slots keyed by USER_ID, mirroring the recent-based
	 *                          branch of {@see Queue::getCountFreeSlotsOperators()}
	 *                          (`Queue::MAX_CHAT` minus the operator's open-line
	 *                          `b_im_recent` count), computed once for every operator
	 *                          in the page instead of once per line.
	 */
	private function loadGlobalFreeSlotsByUser(array $userIds): array
	{
		$map = [];

		if (empty($userIds) || !Loader::includeModule('im'))
		{
			return $map;
		}

		$rows = RecentTable::getList([
			'select' => ['USER_ID', 'CNT'],
			'filter' => [
				'=USER_ID' => $userIds,
				'=ITEM_TYPE' => IM_MESSAGE_OPEN_LINE,
			],
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(*)'),
			],
			'group' => ['USER_ID'],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$map[(int)$row['USER_ID']] = Queue::MAX_CHAT - (int)$row['CNT'];
		}

		return $map;
	}

	/**
	 * Maps a line's TYPE_MAX_CHAT to the session STATUS threshold used by
	 * {@see Queue::getCountFreeSlotsOperators()}'s busy-count override, or null when
	 * the line does not use one of the three status-based limit types (same mapping,
	 * kept in sync with that method).
	 */
	private function resolveStopStatus(?string $typeMaxChat): ?int
	{
		return match ($typeMaxChat)
		{
			Config::TYPE_MAX_CHAT_ANSWERED_NEW => Session::STATUS_CLIENT_AFTER_OPERATOR,
			Config::TYPE_MAX_CHAT_ANSWERED => Session::STATUS_OPERATOR,
			Config::TYPE_MAX_CHAT_CLOSED => Session::STATUS_WAIT_CLIENT,
			default => null,
		};
	}

	/**
	 * @param array<int, int[]> $configIdsByStopStatus Line CONFIG_IDs grouped by their
	 *                                                   resolved stop-status (see
	 *                                                   {@see self::resolveStopStatus()}).
	 * @param array<int, int[]> $userIdsByConfig Operator USER_IDs per CONFIG_ID.
	 * @return array<int, array<int, int>> Busy session_check counts keyed by
	 *                                      CONFIG_ID, then by OPERATOR_ID.
	 *
	 * Runs one grouped `b_imopenlines_session_check` query per distinct stop-status
	 * value (at most 3 - see {@see \Bitrix\ImOpenLines\Config::TYPE_MAX_CHAT_ANSWERED},
	 * `_ANSWERED_NEW`, `_CLOSED`), covering every line that resolves to that
	 * stop-status in one shot instead of one query per line.
	 */
	private function loadBusyCountsByStopStatus(array $configIdsByStopStatus, array $userIdsByConfig): array
	{
		$result = [];

		foreach ($configIdsByStopStatus as $stopStatus => $configIds)
		{
			$configIds = array_values(array_unique($configIds));

			$userIds = [];
			foreach ($configIds as $configId)
			{
				foreach ($userIdsByConfig[$configId] ?? [] as $userId)
				{
					$userIds[$userId] = true;
				}
			}
			$userIds = array_keys($userIds);

			if (empty($configIds) || empty($userIds))
			{
				continue;
			}

			$rows = SessionCheckTable::getList([
				'select' => [
					'CONFIG_ID' => 'SESSION.CONFIG_ID',
					'OPERATOR_ID' => 'SESSION.OPERATOR_ID',
					'CNT',
				],
				'filter' => [
					'=SESSION.OPERATOR_ID' => $userIds,
					'=SESSION.CONFIG_ID' => $configIds,
					'<SESSION.STATUS' => $stopStatus,
				],
				'runtime' => [
					new ExpressionField('CNT', 'COUNT(*)'),
				],
				'group' => ['SESSION.CONFIG_ID', 'SESSION.OPERATOR_ID'],
			])->fetchAll();

			foreach ($rows as $row)
			{
				$result[(int)$row['CONFIG_ID']][(int)$row['OPERATOR_ID']] = (int)$row['CNT'];
			}
		}

		return $result;
	}

	/**
	 * Normalizes the incoming `hasFreeSlots` REST filter (`'Y'`/`'N'`/bool/`'1'`/`'0'`)
	 * into a strict bool, or null when the filter was not provided.
	 *
	 * `(bool)$value` is not safe here: `(bool)'N'` is `true` in PHP because it is a
	 * non-empty string, which would silently turn `hasFreeSlots=N` into "has slots".
	 */
	private function normalizeHasFreeSlotsFilter(mixed $value): ?bool
	{
		if ($value === null)
		{
			return null;
		}

		if (is_bool($value))
		{
			return $value;
		}

		$normalized = mb_strtoupper((string)$value);

		if (in_array($normalized, ['Y', '1', 'TRUE'], true))
		{
			return true;
		}

		if (in_array($normalized, ['N', '0', 'FALSE'], true))
		{
			return false;
		}

		return null;
	}

	/**
	 * Normalizes the caller-supplied id list filters (configId/userId to positive ints),
	 * dedupes them and rejects any list exceeding {@see FilterNormalizer::MAX_LIST_SIZE} -
	 * these go straight into `=CONFIG_ID`/`=USER_ID` and, under a derived filter, also drive
	 * the materialize/enrich volume, so an unbounded caller-controlled list is an app-level
	 * DoS vector. A list that normalizes to empty (e.g. [0]) is an invalid filter, not a
	 * silent "match everything".
	 */
	private function normalizeListFilters(array $filter, Result $result): array
	{
		foreach (['configId', 'userId'] as $key)
		{
			if (!array_key_exists($key, $filter))
			{
				continue;
			}

			$values = FilterNormalizer::idList($filter[$key]);

			if (FilterNormalizer::exceedsLimit($values))
			{
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_OPERATOR_LIST_FILTER_LIST_TOO_LARGE', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			if (empty($values))
			{
				$result->addError(new Error(
					Loc::getMessage('IMOL_STAT_OPERATOR_LIST_EMPTY_FILTER_LIST', ['#FIELD#' => $key]),
					self::ERROR_INVALID_FILTER
				));

				return $filter;
			}

			$filter[$key] = $values;
		}

		return $filter;
	}

	private function normalizeLimit(int $limit): int
	{
		return $limit > 0 && $limit <= self::MAX_LIMIT ? $limit : self::DEFAULT_LIMIT;
	}
}
