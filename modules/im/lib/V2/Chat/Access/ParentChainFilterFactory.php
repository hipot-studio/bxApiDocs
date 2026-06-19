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
		private readonly OpenChatAccessPolicy $policy,
		private readonly TypeRegistry $typeRegistry,
		private readonly ChatAncestorNavigator $navigator,
	) {}

	public function forUser(
		int $userId,
		TreeOrigin $origin = new TreeOrigin('CHAT.PARENT_ID', 'CHAT.ID', 'CHAT'),
		int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	): ParentChainForUserFilter
	{
		$openCondition = null;
		if ($this->policy->canSkipMembershipForOpenChats($userId))
		{
			$condition = $this->typeRegistry->getOpenTypeCondition();
			$openCondition = $condition->hasConditions() ? $condition : null;
		}

		return new ParentChainForUserFilter($this->navigator, $userId, $origin, $maxDepth, $openCondition);
	}

	public function forChat(
		int $chatId,
		string $userIdField = 'USER_ID',
	): ParentChainForChatFilter
	{
		$chat = Chat::getInstance($chatId);
		$ancestorIds = [];

		$current = $chat;
		while ($current->hasParent() && count($ancestorIds) < ChatAncestorNavigator::DEFAULT_DEPTH)
		{
			$ancestorIds[] = $current->getParentChatId();
			$current = Chat::getInstance($current->getParentChatId());
		}

		return new ParentChainForChatFilter($userIdField, $ancestorIds);
	}
}