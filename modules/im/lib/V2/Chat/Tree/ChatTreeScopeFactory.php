<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

final class ChatTreeScopeFactory
{
	public function __construct(
		private readonly TypeRegistry $typeRegistry,
	) {}

	public function forParent(
		int $parentId,
		int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	): ChatTreeScope
	{
		return new ChatTreeScope($parentId, maxDepth: $maxDepth);
	}

	public function forParentDirectChildren(int $parentId): ChatTreeScope
	{
		return $this->forParent($parentId, 1);
	}

	/**
	 * The threads of a chat and nothing else: nested chats living their own life under the same parent
	 * (channels and task chats of a project) stay outside. A single level is the whole scope, because a
	 * thread hangs on its parent directly and a thread of its own is denied to it by the relation registry
	 * of the tree, so a deeper level would only add a join over an empty set.
	 */
	public function forCommentChildren(int $parentId): ChatTreeScope
	{
		$commentType = $this->typeRegistry->getByLiteralAndEntity(Chat::IM_TYPE_COMMENT, null);

		return new ChatTreeScope($parentId, new TypeCondition(include: [$commentType]), maxDepth: 1);
	}

	public function forRecentSection(
		string $recentSection,
		?int $parentId,
		int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	): ChatTreeScope
	{
		$typeCondition = $this->typeRegistry->getConditionByRecentSection($recentSection);

		return new ChatTreeScope($parentId, $typeCondition, $maxDepth);
	}
}
