<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

class ParentChainFilterFactory
{
	/** @var array<string,array<int,true>> ancestors the user has a relation with, keyed by user and chain */
	private array $ancestorsWithRelation = [];

	/**
	 * $policy carries no default on purpose: a default value is taken before the container is asked,
	 * so the factory would hold its own instance while {@see SingleLevelAccessFilterFactory} holds the
	 * located one - and a policy substituted in the locator would be honoured by {@see forUser()} and
	 * silently ignored by {@see forKnownAncestors()}.
	 */
	public function __construct(
		private readonly SingleLevelAccessFilterFactory $singleLevelFilterFactory,
		private readonly ChatAncestorNavigator $navigator,
		private readonly TypeRegistry $typeRegistry,
		private readonly OpenChatAccessPolicy $policy,
	) {}

	public function forUser(
		int $userId,
		TreeOrigin $origin = new TreeOrigin('CHAT.PARENT_ID', 'CHAT.ID', 'CHAT'),
		int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	): ParentChainForUserFilter
	{
		return new ParentChainForUserFilter(
			$this->navigator,
			$this->singleLevelFilterFactory,
			$this->typeRegistry,
			$userId,
			$origin,
			$maxDepth,
		);
	}

	/**
	 * Same chain condition as {@see forUser()}, but the ancestor relations are matched against a
	 * USER_ID column of the query instead of a constant, so one statement serves a whole set of users.
	 *
	 * The open-chat exemption stays resolved for a single user, so every user the query can return
	 * must share it: group the users by
	 * {@see OpenChatAccessPolicy::canSkipMembershipForOpenChats()} and pass a member of the group.
	 */
	public function forUsersColumn(
		int $policyGroupUserId,
		string $userIdColumn,
		TreeOrigin $origin = new TreeOrigin('CHAT.PARENT_ID', 'CHAT.ID', 'CHAT'),
		int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	): ParentChainForUserFilter
	{
		return new ParentChainForUserFilter(
			$this->navigator,
			$this->singleLevelFilterFactory,
			$this->typeRegistry,
			$policyGroupUserId,
			$origin,
			$maxDepth,
			$userIdColumn,
		);
	}

	/**
	 * The chain condition of {@see forUser()} for a driving set whose ancestors are known in PHP and
	 * shared by every row - a collab with its direct children. The levels become constants, so the
	 * statement keeps no ancestor lift by PARENT_ID ({@see ParentChainForKnownAncestorsFilter}).
	 *
	 * @param int[] $ancestorIds ancestors of every driving row, ordered from the nearest parent up;
	 *        the chain is taken up to {@see ChatAncestorNavigator::DEFAULT_DEPTH}, as the joining
	 *        forms do, and ends at the first absent parent
	 * @param string|null $userIdColumn same meaning and same invariant as in {@see forUsersColumn()}:
	 *        the open-chat exemption stays resolved for $userId, so every user the query can return
	 *        must share it.
	 */
	public function forKnownAncestors(
		int $userId,
		array $ancestorIds,
		?string $userIdColumn = null,
	): ParentChainForKnownAncestorsFilter
	{
		return new ParentChainForKnownAncestorsFilter(
			$this,
			$this->policy,
			$this->typeRegistry,
			$userId,
			self::normalizeAncestorIds($ancestorIds),
			$userIdColumn,
		);
	}

	/**
	 * Ancestors the user has a relation with: one probe on (CHAT_ID, USER_ID) per user and chain. The
	 * map lives here rather than in the filter because a filter is built anew for every application,
	 * while one resolution applies the same chain several times - the collab candidate query walks the
	 * children chain once per narrowing chunk and once more for the no-unread branch
	 * ({@see \Bitrix\Im\V2\Recent\PreviewSource\CollabCandidateQuery}).
	 *
	 * Existence alone, with no mute or hidden requirement - exactly what the relation join of
	 * {@see ParentChainForUserFilter} matches.
	 *
	 * The map holds only while the relations cannot change, which the caller states by
	 * {@see resetAncestorRelations()}; it is not a request cache.
	 *
	 * @param int[] $ancestorIds
	 * @return array<int,true>
	 */
	public function getAncestorsWithRelation(int $userId, array $ancestorIds): array
	{
		if (empty($ancestorIds))
		{
			return [];
		}

		$key = $userId . ':' . implode(',', $ancestorIds);

		return $this->ancestorsWithRelation[$key] ??= self::loadAncestorsWithRelation($userId, $ancestorIds);
	}

	/**
	 * Opens a new stretch of work over an unchanged relation state. A caller that memoizes across
	 * several statements has to call this before the first of them, because the factory outlives any
	 * single piece of work: it is located as a service and kept for the whole request, while relations
	 * may be written between two pieces of work of that request.
	 */
	public function resetAncestorRelations(): void
	{
		$this->ancestorsWithRelation = [];
	}

	/**
	 * @param int[] $ancestorIds
	 * @return array<int,true>
	 */
	private static function loadAncestorsWithRelation(int $userId, array $ancestorIds): array
	{
		$found = [];

		$rows = RelationTable::query()
			->setSelect(['CHAT_ID'])
			->whereIn('CHAT_ID', $ancestorIds)
			->where('USER_ID', $userId)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$found[(int)$row['CHAT_ID']] = true;
		}

		return $found;
	}

	/**
	 * @param int[] $ancestorIds
	 * @return int[]
	 */
	private static function normalizeAncestorIds(array $ancestorIds): array
	{
		$normalized = [];

		foreach ($ancestorIds as $ancestorId)
		{
			$ancestorId = (int)$ancestorId;
			if ($ancestorId <= 0 || count($normalized) >= ChatAncestorNavigator::DEFAULT_DEPTH)
			{
				break;
			}

			$normalized[] = $ancestorId;
		}

		return $normalized;
	}

	public function forChat(
		int $chatId,
		string $userIdField = 'USER_ID',
	): ParentChainForChatFilter
	{
		return $this->forAncestors($this->requiredAncestorsOf($chatId), $userIdField);
	}

	/**
	 * The same filter for a caller that already holds the chain, so that a query over several chats of
	 * one parent is built once instead of once per chat.
	 *
	 * @param int[] $ancestorIds
	 */
	public function forAncestors(
		array $ancestorIds,
		string $userIdField = 'USER_ID',
	): ParentChainForChatFilter
	{
		return new ParentChainForChatFilter($userIdField, $ancestorIds);
	}

	/**
	 * Ancestors a member of the chat has to belong to as well, walked as far as the access filters join
	 * the tree.
	 *
	 * @return int[]
	 */
	public function requiredAncestorsOf(int $chatId): array
	{
		return $this->requiredAncestorsOfChat(Chat::getInstance($chatId));
	}

	/**
	 * The same for a caller that already holds the chat: a chat standing on its own is then answered
	 * without a read of any kind.
	 *
	 * @return int[]
	 */
	public function requiredAncestorsOfChat(Chat $chat): array
	{
		$ancestorIds = [];

		$current = $chat;
		while (
			$current->hasParent()
			&& $current->requiresParentMembership()
			&& count($ancestorIds) < ChatAncestorNavigator::DEFAULT_DEPTH
		)
		{
			$ancestorIds[] = $current->getParentChatId();
			$current = Chat::getInstance($current->getParentChatId());
		}

		return $ancestorIds;
	}
}
