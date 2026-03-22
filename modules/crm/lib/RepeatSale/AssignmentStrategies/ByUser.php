<?php

namespace Bitrix\Crm\RepeatSale\AssignmentStrategies;

use Bitrix\Crm\Item;

final class ByUser extends Base
{
	private ?array $assignmentIds;

	public function __construct(
		\Bitrix\Crm\RepeatSale\Segment\SegmentItem $segmentItem,
		int $entityTypeId,
		array $items,
	)
	{
		parent::__construct($segmentItem, $entityTypeId, $items);

		$this->assignmentIds = $segmentItem->getAssignmentUserIds();
	}

	public function getAssignmentUserId(Item $item, ?int $lastAssignmentUserId): ?int
	{
		if (empty($this->assignmentIds))
		{
			return 1;
		}

		$indexedArray = array_values($this->assignmentIds);
		if ($lastAssignmentUserId === null)
		{
			return $indexedArray[0];
		}

		$key = array_search($lastAssignmentUserId, $indexedArray, true);

		if ($key === false || $key >= count($indexedArray) - 1)
		{
			return $indexedArray[0];
		}

		return $indexedArray[$key + 1];
	}
}
