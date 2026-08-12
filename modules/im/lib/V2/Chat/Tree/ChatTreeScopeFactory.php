<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

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
