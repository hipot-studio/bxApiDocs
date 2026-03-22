<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Item;

abstract class Base
{
	public function __construct(
		protected \Bitrix\Crm\RepeatSale\Segment\SegmentItem $segmentItem,
		protected int $entityTypeId,
		protected array $items,
	)
	{
	}

	abstract public function getAssignmentUserId(Item $item, ?int $lastAssignmentUserId): ?int;
}
