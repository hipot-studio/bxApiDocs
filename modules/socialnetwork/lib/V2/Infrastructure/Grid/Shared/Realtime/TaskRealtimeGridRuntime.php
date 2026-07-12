<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Main\Grid;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class TaskRealtimeGridRuntime
{
	public function __construct(
		public readonly Grid\Grid $grid,
		public readonly ConditionTree $filter,
		public readonly array $sort,
		public readonly int $offset,
		public readonly int $limit,
		public readonly array $select,
		public readonly int $contextUserId,
		public readonly bool $isScrum,
		public readonly bool $withImage,
		public readonly bool $withMembers,
		public readonly bool $withOwner,
		public readonly bool $withTags = false,
		public readonly bool $withRelationDate = false,
		public readonly bool $withViewDate = false,
	)
	{
	}
}
