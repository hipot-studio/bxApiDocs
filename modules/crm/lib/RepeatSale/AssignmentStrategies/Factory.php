<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\RepeatSale\Segment\AssignmentType;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;

final class Factory
{
	public static function getStrategy(AssignmentType $assignmentType, SegmentItem $segmentItem, int $entityTypeId, array $items): ?Base
	{
		return match ($assignmentType)
		{
			AssignmentType::byUser => new ByUser($segmentItem, $entityTypeId, $items),
			AssignmentType::byClient => new ByClient($segmentItem, $entityTypeId, $items),
			AssignmentType::byClientLastDeal => new ByClientLastDeal($segmentItem, $entityTypeId, $items),
			default => null,
		};
	}
}
