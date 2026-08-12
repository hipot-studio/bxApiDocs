<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

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
		private ParentChainFilterFactory $parentChainFilterFactory,
	) {}

	public function resolve(int $collabChatId, int $userId): PreviewSource
	{
		if (!Features::isCollabPreviewSourceEnabled())
		{
			return $this->mainChatSource($collabChatId);
		}

		$candidateIds = $this->fetchAccessibleCandidates($collabChatId, $userId);
		$freshUnreadByChat = $this->unreadFreshnessQuery->fetch($collabChatId, $candidateIds, $userId);

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

		// The last-own map is user-independent (per-chat MAX) and the candidate sets of one collab's
		// members overlap heavily, so it is memoized across the whole fan-out with delta loading: each
		// chunk only queries the candidate ids not yet resolved. A resolved-but-message-less chat is
		// cached as null so it is never re-queried.
		$lastOwnCache = [];

		$result = [];
		foreach (array_chunk($userIds, self::FAN_OUT_CHUNK_SIZE) as $chunkUserIds)
		{
			$candidatesByUser = [];
			foreach ($chunkUserIds as $userId)
			{
				$candidatesByUser[$userId] = $this->fetchAccessibleCandidates($collabChatId, $userId);
			}

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

			foreach ($chunkUserIds as $userId)
			{
				$result[$userId] = $this->resolveSingle(
					$collabChatId,
					$userId,
					$candidatesByUser[$userId],
					$freshUnreadByUser[$userId] ?? [],
					$lastOwnLoader,
				);
			}
		}

		return $result;
	}

	/**
	 * @param int[] $candidateIds accessible, not-hidden, not-muted candidates of this collab
	 * @param array<int,int> $freshUnreadByChat chatId => freshest unread MESSAGE_ID
	 * @param callable():array<int,?int>|null $lastOwnLoader shared last-own map loader of the fan-out
	 *        path; must cover (be a superset of) $candidateIds. Null = per-user statements (the
	 *        single-user paths).
	 */
	protected function resolveSingle(
		int $collabChatId,
		int $userId,
		array $candidateIds,
		array $freshUnreadByChat,
		?callable $lastOwnLoader = null,
	): PreviewSource
	{
		if (empty($candidateIds))
		{
			return $this->mainChatSource($collabChatId);
		}

		$ranked = [];
		foreach ($candidateIds as $candidateId)
		{
			if (isset($freshUnreadByChat[$candidateId]))
			{
				$ranked[$candidateId] = $freshUnreadByChat[$candidateId];
			}
		}

		if (!empty($ranked))
		{
			arsort($ranked);
			$winner = (int)array_key_first($ranked);

			$ownFreshest = $this->ownMessageQuery->freshestUnread($winner, $userId);
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

		// No unread anywhere: pick the last own message among the candidates.
		$lastOwnByChat = $lastOwnLoader !== null
			? $lastOwnLoader()
			: $this->ownMessageQuery->lastOwnBatch($candidateIds);
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
	 * @return int[] candidate chat ids
	 */
	protected function fetchAccessibleCandidates(int $collabChatId, int $userId): array
	{
		$query = ChatTable::query()
			->setSelect(['ID'])
			->where(
				Query::filter()
					->logic('OR')
					->where('PARENT_ID', $collabChatId)
					->where('ID', $collabChatId),
			)
			// Exclude comment chats defensively: their unread is rolled up by UnreadFreshnessQuery.
			// The collab itself is COLLAB / OPEN_COLLAB, so this never drops the main chat.
			->whereNot('TYPE', Chat::IM_TYPE_COMMENT)
		;

		$query->registerRuntimeField(
			'OWN_REL',
			(new Reference(
				'OWN_REL',
				RelationTable::class,
				Join::on('ref.CHAT_ID', 'this.ID')
					->where('ref.USER_ID', $userId),
			))->configureJoinType(Join::TYPE_INNER),
		);
		$query->where('OWN_REL.NOTIFY_BLOCK', 'N');
		$query->where('OWN_REL.IS_HIDDEN', 'N');

		// A hidden chat is just a removed b_im_recent row, so a candidate must still be present in recent.
		$query->registerRuntimeField(
			'OWN_RECENT',
			(new Reference(
				'OWN_RECENT',
				RecentTable::class,
				Join::on('ref.ITEM_CID', 'this.ID')
					->where('ref.USER_ID', $userId),
			))->configureJoinType(Join::TYPE_INNER),
		);

		$this->parentChainFilterFactory
			->forUser($userId, new TreeOrigin('PARENT_ID', 'ID'))
			->apply($query)
		;

		$candidateIds = [];
		foreach ($query->fetchAll() as $row)
		{
			$candidateIds[] = (int)$row['ID'];
		}

		return $candidateIds;
	}
}
