<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Mute\MuteProvider;

class ChatTreeFilterFactory
{
	public function __construct(
		private readonly ChatAncestorNavigator $navigator,
		private readonly MuteProvider $muteProvider,
		private readonly TypeRegistry $typeRegistry,
		private readonly ChatTreeLevelParents $levelParents,
	) {}

	public function forUnread(int $userId, int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH): SubtreeUnreadFilter
	{
		return new SubtreeUnreadFilter(
			$this->navigator,
			$this->muteProvider,
			$this->typeRegistry,
			$userId,
			$maxDepth,
		);
	}

	public function forTreeScope(ChatTreeScope $scope): ChatAncestorFilter
	{
		return new ChatAncestorFilter(
			$this->navigator,
			parentId: $scope->parentId,
			typeCondition: $scope->typeCondition,
			maxDepth: $scope->maxDepth,
			levelParents: $this->levelParents,
		);
	}

	public function forTreeScopeUnreadCounters(ChatTreeScope $scope): ChatAncestorFilter
	{
		return new ChatAncestorFilter(
			$this->navigator,
			parentId: $scope->parentId,
			typeCondition: $scope->typeCondition,
			maxDepth: $scope->maxDepth,
			origin: TreeOrigin::forUnreadCounters(),
			levelParents: $this->levelParents,
		);
	}
}
