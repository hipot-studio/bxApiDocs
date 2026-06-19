<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

class ChatTreeFilterFactory
{
	public function __construct(
		private readonly ChatAncestorNavigator $navigator,
	) {}

	public function forUnread(int $userId, int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH): SubtreeUnreadFilter
	{
		return new SubtreeUnreadFilter($this->navigator, $userId, $maxDepth);
	}
}
