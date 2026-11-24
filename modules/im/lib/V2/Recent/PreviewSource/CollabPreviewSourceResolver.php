<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;

/**
 * Per-user resolver of the preview source (chat + own message) for a collab recent row.
 *
 * Strictly per-user: mute/hidden state differs between members, so it must never be derived from a
 * shared denormalized value. The preview shows the user's own freshest unread message of the winning
 * chat; if the counter is composed only of thread replies, the last own message of the channel itself.
 */
readonly class CollabPreviewSourceResolver
{
	/**
	 * Members per fan-out chunk: bounds the flat statement's USER_ID list and the per-chunk
	 * candidate/result memory. A 200-id list exceeds the MySQL 5.6 eq_range_index_dive_limit of 10
	 * (5.6 then estimates it from index statistics rather than dives), which is measured stable for
	 * this statement under statistics churn on both supported MySQL lines and on PostgreSQL.
	 */
	private const FAN_OUT_CHUNK_SIZE = 200;

	public function __construct(
		private UnreadFreshnessQuery $unreadFreshnessQuery,
		private OwnMessageQuery $ownMessageQuery,
		private CollabCandidateQuery $candidateQuery,
	) {}

	/**
	 * Freshness first, candidates second: the user's unread inside the collab is folded into ancestor
	 * chains ({@see UnreadFreshnessQuery::fetchChains()}), and only the chats met on those chains are
	 * probed for candidacy. Nothing unread in the scope - the candidates are never enumerated and the
	 * winner comes from the denormalized last message. The cost of the hit follows the user's unread,
	 * not the collab's size.
	 */
	public function resolve(int $collabChatId, int $userId): PreviewSource
	{
		if (!Features::isCollabPreviewSourceEnabled())
		{
			return $this->mainChatSource($collabChatId);
		}

		$this->candidateQuery->beginResolution();

		$chainBuckets = $this->unreadFreshnessQuery->fetchChains($collabChatId, $userId);
		if ($chainBuckets === null)
		{
			return $this->resolveCandidatesFirst($collabChatId, $userId);
		}

		$observedChatIds = $chainBuckets->observedChatIds();
		if (empty($observedChatIds))
		{
			return $this->bestByLastMessageSource($collabChatId, $userId);
		}

		$candidateIds = $this->fetchAccessibleCandidates($collabChatId, $userId, $observedChatIds);

		return $this->resolveSingle(
			$collabChatId,
			$userId,
			$candidateIds,
			$chainBuckets->assign($candidateIds),
			isCandidateSetNarrowed: true,
		);
	}

	/**
	 * The order of the dialects without the flat unread statement: freshness is one aggregating query
	 * per depth carrying the candidate ids in SQL, so the candidates have to be enumerated first.
	 */
	private function resolveCandidatesFirst(int $collabChatId, int $userId): PreviewSource
	{
		$candidateIds = $this->fetchAccessibleCandidates($collabChatId, $userId);
		$freshUnreadByChat = $this->unreadFreshnessQuery->fetch($candidateIds, $userId);

		return $this->resolveSingle($collabChatId, $userId, $candidateIds, $freshUnreadByChat);
	}

	/**
	 * Resolves the preview source of one collab for several members in one batch - the fan-out path
	 * (detach, source cleanup, member-wide recompute).
	 *
	 * The heavy freshness lookup runs as one multi-user statement per member chunk
	 * ({@see UnreadFreshnessQuery::fetchForMembers()}) instead of a per-member query: the flat
	 * statement is shared by the whole chunk, while any per-member form pays its full walk once per
	 * member. The candidate lookup and the ranking stay per-user (mute/hidden state differs between
	 * members).
	 *
	 * @param int[] $userIds
	 * @return array<int,PreviewSource> keyed by userId
	 */
	public function resolveForMembers(int $collabChatId, array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $id) => $id > 0,
		)));
		if ($collabChatId <= 0 || empty($userIds))
		{
			return [];
		}

		if (!Features::isCollabPreviewSourceEnabled())
		{
			// One shared immutable PreviewSource instance under every key - intentional, do not clone per user.
			return array_fill_keys($userIds, $this->mainChatSource($collabChatId));
		}

		$this->candidateQuery->beginResolution();

		// The last-own map is user-independent (per-chat MAX) and the candidate sets of one collab's
		// members overlap heavily, so it is memoized across the whole fan-out with delta loading: each
		// chunk only queries the candidate ids not yet resolved. A resolved-but-message-less chat is
		// cached as null so it is never re-queried.
		$lastOwnCache = [];

		$result = [];
		foreach (array_chunk($userIds, self::FAN_OUT_CHUNK_SIZE) as $chunkUserIds)
		{
			$candidatesByUser = $this->fetchAccessibleCandidatesForMembers($collabChatId, $chunkUserIds);

			$freshUnreadByUser = $this->unreadFreshnessQuery->fetchForMembers($candidatesByUser, [$collabChatId]);

			// One loader instance per chunk, one shared cache: &$lastOwnCache makes every chunk's loader
			// top up the same map, so later chunks only fetch candidates no earlier chunk has resolved.
			$lastOwnLoader = function () use (&$lastOwnCache, $candidatesByUser): array
			{
				$missing = [];
				foreach ($candidatesByUser as $candidateIds)
				{
					foreach ($candidateIds as $candidateId)
					{
						if (!array_key_exists($candidateId, $lastOwnCache))
						{
							$missing[$candidateId] = true;
						}
					}
				}

				if (!empty($missing))
				{
					$missing = array_keys($missing);
					$loaded = $this->ownMessageQuery->lastOwnBatch($missing);
					foreach ($missing as $candidateId)
					{
						$lastOwnCache[$candidateId] = $loaded[$candidateId] ?? null;
					}
				}

				return $lastOwnCache;
			};

			// The ranking runs on the shared freshness map alone, so every member's winner is known
			// before the database is asked anything about them - and the freshest unread of the whole
			// chunk comes back grouped by the chat the members picked, instead of one statement per
			// member with a winner.
			$winnerByUser = [];
			foreach ($chunkUserIds as $userId)
			{
				$winner = $this->rankWinner(
					$candidatesByUser[$userId] ?? [],
					$freshUnreadByUser[$userId] ?? [],
				);

				if ($winner !== null)
				{
					$winnerByUser[$userId] = $winner;
				}
			}

			$ownFreshestByUser = $this->ownMessageQuery->freshestUnreadBatch($winnerByUser);

			foreach ($chunkUserIds as $userId)
			{
				$result[$userId] = $this->resolveSingle(
					$collabChatId,
					$userId,
					$candidatesByUser[$userId],
					$freshUnreadByUser[$userId] ?? [],
					$lastOwnLoader,
					$ownFreshestByUser,
				);
			}
		}

		return $result;
	}

	/**
	 * The candidate holding the freshest unread among those the member can see, or null when the member
	 * has no unread in any of them. Reads the shared freshness map and nothing else, so the fan-out can
	 * settle every member's winner before it asks the database about any of them.
	 *
	 * @param int[] $candidateIds
	 * @param array<int,int> $freshUnreadByChat chatId => freshest unread MESSAGE_ID
	 */
	private function rankWinner(array $candidateIds, array $freshUnreadByChat): ?int
	{
		$ranked = [];
		foreach ($candidateIds as $candidateId)
		{
			if (isset($freshUnreadByChat[$candidateId]))
			{
				$ranked[$candidateId] = $freshUnreadByChat[$candidateId];
			}
		}

		if (empty($ranked))
		{
			return null;
		}

		arsort($ranked);

		return (int)array_key_first($ranked);
	}

	/**
	 * @param int[] $candidateIds accessible, not-hidden, not-muted candidates of this collab; on the
	 *        read path already narrowed to the chats met on the user's unread chains, which the
	 *        ranking cannot tell apart from the full set ({@see UnreadChainBuckets})
	 * @param array<int,int> $freshUnreadByChat chatId => freshest unread MESSAGE_ID
	 * @param callable():array<int,?int>|null $lastOwnLoader shared last-own map loader of the fan-out
	 *        path; must cover (be a superset of) $candidateIds. Null = per-user statements (the
	 *        single-user paths).
	 * @param array<int,int>|null $ownFreshestByUser freshest unread of each member in the candidate
	 *        their ranking picked, resolved for the whole chunk ahead of the loop; a member without
	 *        one is absent from the map. Null = per-user statement (the single-user paths).
	 * @param bool $isCandidateSetNarrowed whether $candidateIds is the narrowing of the read path
	 *        rather than the member's full candidate set. Only the narrowed set has to re-ask the
	 *        database in the no-unread branch; a full set is already the answer that branch would
	 *        query for, so it is ranked in place.
	 */
	protected function resolveSingle(
		int $collabChatId,
		int $userId,
		array $candidateIds,
		array $freshUnreadByChat,
		?callable $lastOwnLoader = null,
		?array $ownFreshestByUser = null,
		bool $isCandidateSetNarrowed = false,
	): PreviewSource
	{
		// No early return on an empty candidate set: on the read path it is the narrowed set, and its
		// emptiness says nothing about the full one. The no-unread branch below queries the full set
		// itself, under the same access conditions, and its empty answer yields the same main-chat source.
		$winner = $this->rankWinner($candidateIds, $freshUnreadByChat);

		if ($winner !== null)
		{
			$ownFreshest = $ownFreshestByUser !== null
				? ($ownFreshestByUser[$userId] ?? null)
				: $this->ownMessageQuery->freshestUnread($winner, $userId);
			if ($ownFreshest !== null)
			{
				return new PreviewSource(
					sourceChatId: $winner,
					messageId: $ownFreshest,
					isMainChat: $winner === $collabChatId,
				);
			}

			// Counter is non-zero but built only from thread replies: fall back to the last own channel message.
			$lastOwn = $lastOwnLoader !== null
				? ($lastOwnLoader()[$winner] ?? null)
				: $this->ownMessageQuery->lastOwn($winner);
			if ($lastOwn !== null)
			{
				return new PreviewSource(
					sourceChatId: $winner,
					messageId: $lastOwn,
					isMainChat: $winner === $collabChatId,
				);
			}

			return $this->mainChatSource($collabChatId);
		}

		if ($isCandidateSetNarrowed)
		{
			// The narrowed set says nothing about the full one, so the branch asks the database for
			// the full set itself, ranked inside the statement.
			return $this->bestByLastMessageSource($collabChatId, $userId);
		}

		// No unread anywhere and the full candidate set already in hand: the winner is the last own
		// message among them, taken from the map the whole chunk shares on the fan-out path and from
		// one batched statement on the single-user paths without the chain aggregate.
		$lastOwnByChat = $lastOwnLoader !== null
			? $lastOwnLoader()
			: $this->ownMessageQuery->lastOwnBatch($candidateIds)
		;
		$bestChatId = 0;
		$bestMessageId = 0;
		foreach ($candidateIds as $candidateId)
		{
			$lastOwn = $lastOwnByChat[$candidateId] ?? null;
			if ($lastOwn !== null && $lastOwn > $bestMessageId)
			{
				$bestMessageId = $lastOwn;
				$bestChatId = $candidateId;
			}
		}

		if ($bestChatId > 0)
		{
			return new PreviewSource(
				sourceChatId: $bestChatId,
				messageId: $bestMessageId,
				isMainChat: $bestChatId === $collabChatId,
			);
		}

		return $this->mainChatSource($collabChatId);
	}

	/**
	 * The read path's branch for "nothing unread": the winner is ranked by the denormalized last
	 * message inside the statement, so the candidates never reach PHP and b_im_message is never
	 * aggregated. No candidate survives the history border - the main chat, exactly as an empty
	 * candidate set used to yield. Only the narrowed set comes here; a caller already holding the full
	 * set ranks it in place instead ({@see resolveSingle()}).
	 */
	private function bestByLastMessageSource(int $collabChatId, int $userId): PreviewSource
	{
		$best = $this->candidateQuery->bestByLastMessage($collabChatId, $userId);
		if ($best === null)
		{
			return $this->mainChatSource($collabChatId);
		}

		return new PreviewSource(
			sourceChatId: $best['chatId'],
			messageId: $best['messageId'],
			isMainChat: $best['chatId'] === $collabChatId,
		);
	}

	protected function mainChatSource(int $collabChatId): PreviewSource
	{
		$chat = Chat::getInstance($collabChatId);

		return PreviewSource::mainChat($collabChatId, $chat->getLastMessageId() ?? 0);
	}

	/**
	 * Accessible candidates of the collab: direct children (PARENT_ID == collab) plus the collab
	 * chat itself, restricted to chats with a not-hidden, not-muted relation of the user and gated
	 * through the parent-membership-aware access filter. Threads are excluded (their unread is
	 * rolled up by UnreadFreshnessQuery).
	 *
	 * @param int[]|null $restrictToChatIds the chats met on the user's unread chains; the statement
	 *        then probes them by PRIMARY instead of walking every child of the collab
	 * @return int[] candidate chat ids
	 */
	protected function fetchAccessibleCandidates(
		int $collabChatId,
		int $userId,
		?array $restrictToChatIds = null,
	): array
	{
		return $this->candidateQuery->forUser($collabChatId, $userId, $restrictToChatIds);
	}

	/**
	 * The same candidate set as {@see fetchAccessibleCandidates()} for a whole member chunk. The form
	 * and the open-chat policy grouping it depends on belong to {@see CollabCandidateQuery}, which is
	 * the only place in the module deciding which members may share one statement.
	 *
	 * @param int[] $userIds
	 * @return array<int,int[]> userId => candidate chat ids, one entry per input member
	 */
	protected function fetchAccessibleCandidatesForMembers(int $collabChatId, array $userIds): array
	{
		return $this->candidateQuery->forMembers($collabChatId, $userIds);
	}
}
