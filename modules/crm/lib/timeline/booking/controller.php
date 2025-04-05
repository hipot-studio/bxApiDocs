<?php

namespace Bitrix\Crm\Timeline\Booking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Timeline;

final class Controller extends Timeline\Controller
{
	public function onBookingCreated(array $bindings, array $booking): ?int
	{
		$timelineEntryId = $this->getTimelineEntryFacade()->create(
			Timeline\TimelineEntry\Facade::BOOKING,
			[
				'TYPE_ID' => Timeline\TimelineType::LOG_MESSAGE,
				'TYPE_CATEGORY_ID' => Timeline\LogMessageType::BOOKING_CREATED,
				'AUTHOR_ID' => $booking['createdBy'],
				'SETTINGS' => $booking,
				'BINDINGS' => $bindings,
				'ASSOCIATED_ENTITY_ID' => $booking['id'],
			],
		);

		if ($timelineEntryId)
		{
			foreach ($bindings as $binding)
			{
				$identifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

				$this->sendPullEventOnAdd($identifier, $timelineEntryId);
			}
		}

		return $timelineEntryId;
	}

	public function onWaitListItemCreated(array $bindings, array $waitListItem): ?int
	{
		return $this->createWaitListItemTimelineEntry(
			typeCategoryId: Timeline\LogMessageType::WAIT_LIST_ITEM_CREATED,
			bindings: $bindings,
			settings: $waitListItem,
			authorId: $waitListItem['createdBy'],
			entityId: $waitListItem['id'],
		);
	}

	public function onWaitListItemDeleted(array $bindings, array $waitListItem, int $removedBy): ?int
	{
		return $this->createWaitListItemTimelineEntry(
			typeCategoryId: Timeline\LogMessageType::WAIT_LIST_ITEM_DELETED,
			bindings: $bindings,
			settings: $waitListItem,
			authorId: $removedBy,
			entityId: $waitListItem['id'],
		);
	}

	private function createWaitListItemTimelineEntry(
		int $typeCategoryId,
		array $bindings,
		array $settings,
		int $authorId,
		int $entityId,
	): ?int
	{
		$timelineEntryId = $this->getTimelineEntryFacade()->create(
			Timeline\TimelineEntry\Facade::WAIT_LIST_ITEM,
			[
				'TYPE_ID' => Timeline\TimelineType::LOG_MESSAGE,
				'TYPE_CATEGORY_ID' => $typeCategoryId,
				'AUTHOR_ID' => $authorId,
				'SETTINGS' => $settings,
				'BINDINGS' => $bindings,
				'ASSOCIATED_ENTITY_ID' => $entityId,
			],
		);

		if ($timelineEntryId)
		{
			foreach ($bindings as $binding)
			{
				$identifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

				$this->sendPullEventOnAdd($identifier, $timelineEntryId);
			}
		}

		return $timelineEntryId ?: null;
	}
}
