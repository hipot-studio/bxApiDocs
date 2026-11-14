<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

class ParentChainFilterFactory
{
	public function __construct(
		private readonly SingleLevelAccessFilterFactory $singleLevelFilterFactory,
		private readonly ChatAncestorNavigator $navigator,
		private readonly TypeRegistry $typeRegistry,
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
