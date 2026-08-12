<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat\Type\TypeCondition;

final readonly class ChatTreeScope
{
	public function __construct(
		public ?int $parentId,
		public ?TypeCondition $typeCondition = null,
		public int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	) {}

	public function isPossible(): bool
	{
		return ($this->parentId === null || $this->parentId >= 0)
			&& ($this->typeCondition === null || $this->typeCondition->isPossible())
		;
	}
}
