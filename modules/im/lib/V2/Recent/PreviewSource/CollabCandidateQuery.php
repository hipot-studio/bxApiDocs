<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Access\OpenChatAccessPolicy;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Entity\User\UserFactory;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The chats of a collab a member may see in the preview: the collab itself and its direct children,
 * each with a not-hidden, not-muted relation of the member and a row in recent. Threads and comments
 * are left out - their unread is rolled up by {@see UnreadFreshnessQuery}.
 *
 * Two branches rather than one PARENT_ID = C OR ID = C: the disjunction is not sargable, and the two
 * sides do not share an ancestor chain anyway - a child stands under the collab, the collab under its
 * own parent. Both chains are known in PHP, so they go in as constants
 * ({@see ParentChainFilterFactory::forKnownAncestors()}) and the statement keeps no lift by PARENT_ID.
 * A collab stands at PARENT_ID = 0, so the collab branch is handed an empty chain and asks nothing;
 * the ancestor relation of the children branch is one probe held by the factory for the whole hit
 * ({@see ParentChainFilterFactory::getAncestorsWithRelation()}), not one per branch and pass.
 *
 * This object owns the open-chat policy grouping of the fan-out: the ancestor exemption of the
 * member-column form is resolved for one member, so a statement may only carry members that answer
 * {@see OpenChatAccessPolicy::canSkipMembershipForOpenChats()} the same way
 * (im/.ai/knowledge-base/access/access.md). There is no second place in the module deciding it.
 */
class CollabCandidateQuery
{
	/**
	 * How deep the no-unread branch looks when it picks the freshest candidate by the denormalized
	 * last message. The order is the one the branch ranks by, so K rows are enough unless all of them
	 * stand behind their history border - then the branch reports nothing and the caller falls back to
	 * the main chat, the way it did on an empty candidate set.
	 *
	 * The limit is applied before the border cut, so the cut cannot reach past the K-th candidate: a
	 * member whose K freshest chats all have their history cleared gets the main chat, while the full
	 * enumeration would have found the K+1-th. That is the accepted cost of never carrying the
	 * candidates into PHP (ADR-im-collab-preview-candidates-2026-09-18, section 5, "Top-K"); the case
	 * is pinned by CollabCandidateQueryTest::testTopCandidatesBehindTheBorderHideTheDeeperCandidate.
	 */
	private const BEST_CANDIDATE_LIMIT = 20;

	/** Column the batched statements match every per-member join against. */
	private const MEMBER_USER_COLUMN = 'OWN_REL.USER_ID';

	/**
	 * Ids per narrowing statement. The narrowing follows the member's unread and has no upper bound of
	 * its own, while the repository caps an IN list at 300 values
	 * (.ai/knowledge-base/notes/backend/references/database.md). Overridable so a case can observe the
	 * chunking without building a collab of that size.
	 */
	protected const RESTRICT_CHUNK_SIZE = 300;

	public function __construct(
		private readonly ParentChainFilterFactory $parentChainFilterFactory,
		private readonly OwnMessageQuery $ownMessageQuery,
		private readonly OpenChatAccessPolicy $openChatAccessPolicy,
	) {}

	/**
	 * States that one resolution begins: the chain factory memoizes the ancestor relation of the member
	 * across the statements of this object, and that memo must not be carried over from an earlier
	 * resolution - nothing writes relations inside a resolution, anything may between two of them.
	 */
	public function beginResolution(): void
	{
		$this->parentChainFilterFactory->resetAncestorRelations();
	}

	/**
	 * @param int[]|null $restrictToChatIds when given, the answer stays within these ids; the branches
	 *        then probe the driving rows by PRIMARY instead of walking the children of the collab
	 * @return int[] candidate chat ids
	 */
	public function forUser(int $collabChatId, int $userId, ?array $restrictToChatIds = null): array
	{
		if ($collabChatId <= 0 || $userId <= 0)
		{
			return [];
		}

		$restrictToChatIds = $restrictToChatIds === null ? null : self::normalizeIds($restrictToChatIds);
		if ($restrictToChatIds === [])
		{
			return [];
		}

		$collabAncestorIds = self::collabAncestorIds($collabChatId);

		// The collab is never its own child, so a narrowing holding nothing else leaves the children
		// branch with a predicate no row can satisfy - skip the statement instead of running it empty.
		$childRestrictIds = $restrictToChatIds === null
			? null
			: array_values(array_diff($restrictToChatIds, [$collabChatId]))
		;

		$candidateIds = [];
		if ($childRestrictIds !== [])
		{
			$childAncestorIds = self::childAncestorIds($collabChatId, $collabAncestorIds);
			foreach (self::restrictChunks($childRestrictIds) as $chunk)
			{
				$childrenQuery = $this->childrenQuery($collabChatId, $chunk);
				$this->applyMemberScope($childrenQuery, $userId);
				$this->applyChain($childrenQuery, $userId, $childAncestorIds);
				$candidateIds = array_merge($candidateIds, $this->fetchIds($childrenQuery));
			}
		}

		if ($restrictToChatIds !== null && !in_array($collabChatId, $restrictToChatIds, true))
		{
			return $candidateIds;
		}

		$collabQuery = $this->collabQuery($collabChatId);
		$this->applyMemberScope($collabQuery, $userId);
		$this->applyChain($collabQuery, $userId, $collabAncestorIds);

		return array_merge($candidateIds, $this->fetchIds($collabQuery));
	}

	/**
	 * The same candidate set as {@see forUser()} for a whole member chunk: one pair of branches per
	 * open-chat policy group instead of one per member. The chat-side restriction is shared by the
	 * group, while the mute/hidden relation, the recent row and the ancestor relations are matched
	 * against the driving member column, so each member is still resolved on their own state.
	 *
	 * @param int[] $userIds
	 * @return array<int,int[]> userId => candidate chat ids, one entry per input member
	 */
	public function forMembers(int $collabChatId, array $userIds): array
	{
		$userIds = self::normalizeIds($userIds);
		$candidatesByUser = array_fill_keys($userIds, []);
		if ($collabChatId <= 0 || empty($userIds))
		{
			return $candidatesByUser;
		}

		$collabAncestorIds = self::collabAncestorIds($collabChatId);
		$childAncestorIds = self::childAncestorIds($collabChatId, $collabAncestorIds);

		foreach ($this->groupByOpenChatPolicy($userIds) as $groupUserIds)
		{
			$policyGroupUserId = $groupUserIds[0];

			$childrenQuery = $this->childrenQuery($collabChatId, null);
			$this->applyMemberColumnScope($childrenQuery, $groupUserIds);
			$this->applyChain($childrenQuery, $policyGroupUserId, $childAncestorIds, self::MEMBER_USER_COLUMN);
			$this->streamMemberIds($childrenQuery, $candidatesByUser);

			$collabQuery = $this->collabQuery($collabChatId);
			$this->applyMemberColumnScope($collabQuery, $groupUserIds);
			$this->applyChain($collabQuery, $policyGroupUserId, $collabAncestorIds, self::MEMBER_USER_COLUMN);
			$this->streamMemberIds($collabQuery, $candidatesByUser);
		}

		return $candidatesByUser;
	}

	/**
	 * The candidate holding the freshest message when the member has nothing unread in the collab.
	 *
	 * Ranked by the denormalized LAST_MESSAGE_ID inside the statement, so the branch never carries the
	 * candidate ids into PHP and never aggregates b_im_message. Only the top rows come back, and the
	 * history border is applied to them by {@see OwnMessageQuery::filterWithinHistoryBorder()} - the
	 * same cut the batched form applies, taken here over K rows instead of the whole candidate set.
	 *
	 * @return array{chatId:int,messageId:int}|null null is two-valued on purpose and the caller treats
	 *         both the same way ({@see CollabPreviewSourceResolver::bestByLastMessageSource()}): the
	 *         member has no candidate carrying a message at all, or every one of the top
	 *         {@see BEST_CANDIDATE_LIMIT} stands behind its history border. Neither the answer nor the
	 *         log tells the two apart.
	 */
	public function bestByLastMessage(int $collabChatId, int $userId): ?array
	{
		if ($collabChatId <= 0 || $userId <= 0)
		{
			return null;
		}

		$collabAncestorIds = self::collabAncestorIds($collabChatId);

		$childrenQuery = $this->childrenQuery($collabChatId, null);
		$this->applyMemberScope($childrenQuery, $userId);
		$this->applyChain($childrenQuery, $userId, self::childAncestorIds($collabChatId, $collabAncestorIds));
		$this->selectLastMessage($childrenQuery);
		$childrenQuery
			->setOrder(['LAST_MESSAGE_ID' => 'DESC'])
			->setLimit(self::BEST_CANDIDATE_LIMIT)
		;
		$lastMessageByChat = $this->fetchLastMessages($childrenQuery);

		$collabQuery = $this->collabQuery($collabChatId);
		$this->applyMemberScope($collabQuery, $userId);
		$this->applyChain($collabQuery, $userId, $collabAncestorIds);
		$this->selectLastMessage($collabQuery);
		$lastMessageByChat += $this->fetchLastMessages($collabQuery);

		$bestChatId = 0;
		$bestMessageId = 0;
		foreach ($this->ownMessageQuery->filterWithinHistoryBorder($lastMessageByChat) as $chatId => $messageId)
		{
			if ($messageId > $bestMessageId)
			{
				$bestChatId = $chatId;
				$bestMessageId = $messageId;
			}
		}

		if ($bestChatId <= 0)
		{
			return null;
		}

		return ['chatId' => $bestChatId, 'messageId' => $bestMessageId];
	}

	/**
	 * @param int[]|null $restrictToChatIds
	 */
	private function childrenQuery(int $collabChatId, ?array $restrictToChatIds): Query
	{
		$query = $this->drivingQuery()->where('PARENT_ID', $collabChatId);

		if ($restrictToChatIds !== null)
		{
			$query->whereIn('ID', $restrictToChatIds);
		}

		return $query;
	}

	private function collabQuery(int $collabChatId): Query
	{
		return $this->drivingQuery()->where('ID', $collabChatId);
	}

	private function drivingQuery(): Query
	{
		return ChatTable::query()
			->setSelect(['ID'])
			// Exclude comment chats defensively: their unread is rolled up by UnreadFreshnessQuery.
			// The collab itself is COLLAB / OPEN_COLLAB, so this never drops the main chat.
			->whereNot('TYPE', Chat::IM_TYPE_COMMENT)
		;
	}

	private function applyMemberScope(Query $query, int $userId): void
	{
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
	}

	/**
	 * The member scope of the fan-out: the driving relation carries the member, and everything matched
	 * per member - the recent row and the ancestor relations - is joined against its USER_ID column.
	 * The column has to be selected, otherwise the ORM cannot resolve it inside a join condition.
	 *
	 * @param int[] $groupUserIds
	 */
	private function applyMemberColumnScope(Query $query, array $groupUserIds): void
	{
		$query->addSelect(self::MEMBER_USER_COLUMN, 'MEMBER_USER_ID');

		$query->registerRuntimeField(
			'OWN_REL',
			(new Reference(
				'OWN_REL',
				RelationTable::class,
				Join::on('ref.CHAT_ID', 'this.ID')
					->whereIn('ref.USER_ID', $groupUserIds)
					->where('ref.NOTIFY_BLOCK', 'N')
					->where('ref.IS_HIDDEN', 'N'),
			))->configureJoinType(Join::TYPE_INNER),
		);

		$query->registerRuntimeField(
			'OWN_RECENT',
			(new Reference(
				'OWN_RECENT',
				RecentTable::class,
				Join::on('ref.ITEM_CID', 'this.ID')
					->whereColumn('ref.USER_ID', self::MEMBER_USER_COLUMN),
			))->configureJoinType(Join::TYPE_INNER),
		);
	}

	/**
	 * @param int[] $ancestorIds
	 */
	private function applyChain(Query $query, int $userId, array $ancestorIds, ?string $userIdColumn = null): void
	{
		$this->parentChainFilterFactory
			->forKnownAncestors($userId, $ancestorIds, $userIdColumn)
			->apply($query)
		;
	}

	private function selectLastMessage(Query $query): void
	{
		$query
			->addSelect('LAST_MESSAGE_ID')
			->where('LAST_MESSAGE_ID', '>', 0)
		;
	}

	/**
	 * @return int[]
	 */
	private function fetchIds(Query $query): array
	{
		$candidateIds = [];

		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$candidateIds[] = (int)$row['ID'];
		}

		return $candidateIds;
	}

	/**
	 * @param array<int,int[]> $candidatesByUser collected in place
	 */
	private function streamMemberIds(Query $query, array &$candidatesByUser): void
	{
		// Streamed: the statement yields a row per member and chat, and only the ids are kept.
		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$candidatesByUser[(int)$row['MEMBER_USER_ID']][] = (int)$row['ID'];
		}
	}

	/**
	 * @return array<int,int> chatId => LAST_MESSAGE_ID, in the ranked order of the statement
	 */
	private function fetchLastMessages(Query $query): array
	{
		$lastMessageByChat = [];

		$rows = $query->exec();
		while ($row = $rows->fetch())
		{
			$lastMessageByChat[(int)$row['ID']] = (int)$row['LAST_MESSAGE_ID'];
		}

		return $lastMessageByChat;
	}

	/**
	 * Splits the members by the open-chat exemption, which the chain condition resolves once per
	 * statement. Users are loaded in one query first, so the policy reads a warm registry.
	 *
	 * @param int[] $userIds
	 * @return array<int[]> non-empty groups
	 */
	private function groupByOpenChatPolicy(array $userIds): array
	{
		UserFactory::getInstance()->getUsersByIds($userIds);

		$groups = [];
		foreach ($userIds as $userId)
		{
			$canSkip = (int)$this->openChatAccessPolicy->canSkipMembershipForOpenChats($userId);
			$groups[$canSkip][] = $userId;
		}

		return array_values($groups);
	}

	/**
	 * The ancestors of the direct children: the collab, then the ancestors of the collab itself. The
	 * chain is cut to the depth the joining forms walk, and the factory cuts it again at the first
	 * absent parent.
	 *
	 * @param int[] $collabAncestorIds
	 * @return int[]
	 */
	private static function childAncestorIds(int $collabChatId, array $collabAncestorIds): array
	{
		return array_merge([$collabChatId], $collabAncestorIds);
	}

	/**
	 * @return int[] ancestors of the collab itself, from the nearest parent up
	 */
	private static function collabAncestorIds(int $collabChatId): array
	{
		$ancestorIds = [];

		$current = Chat::getInstance($collabChatId);
		while ($current->hasParent() && count($ancestorIds) < ChatAncestorNavigator::DEFAULT_DEPTH)
		{
			$parentChatId = (int)$current->getParentChatId();
			$ancestorIds[] = $parentChatId;
			$current = Chat::getInstance($parentChatId);
		}

		return $ancestorIds;
	}

	/**
	 * The narrowing split into statement-sized pieces; an unrestricted branch stays one statement.
	 *
	 * @param int[]|null $restrictToChatIds
	 * @return array<int[]|null> one entry per statement to run
	 */
	private static function restrictChunks(?array $restrictToChatIds): array
	{
		if ($restrictToChatIds === null)
		{
			return [null];
		}

		return array_chunk($restrictToChatIds, static::RESTRICT_CHUNK_SIZE);
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $ids),
			static fn (int $id): bool => $id > 0,
		)));
	}
}
