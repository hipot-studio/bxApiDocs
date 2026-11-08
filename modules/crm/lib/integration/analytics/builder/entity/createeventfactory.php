<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Entity;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Integration\Analytics\Builder\AnalyticsEventDto;
use Bitrix\Crm\Integration\Analytics\Builder\Robot\CreateEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Context;
use Bitrix\Main\Result;

final class CreateEventFactory
{
	public static function create(
		Item $item,
		Context $context,
		Result $result,
		?AbstractBuilder $event = null,
	): AbstractBuilder
	{
		$analytics = $context->getAnalytics();
		if ($analytics->isEmpty())
		{
			$event = AddEvent::createDefault($item->getEntityTypeId())
				->setSection(Dictionary::SECTION_UNKNOWN)
			;
		}
		else
		{
			$event ??= self::createFromAnalytics($analytics, $item);
			$analytics->fillEventBuilder($event);
		}

		self::fillMissingCreatedItemId($event, $item, $result);
		self::completeKanbanEvent($event, $analytics, $item, $context, $result);

		return $event->setStatus(
			$result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR,
		);
	}

	public static function createFromAnalytics(AnalyticsEventDto $analytics, Item $item): AbstractBuilder
	{
		if ($analytics->getCategory() === Dictionary::CATEGORY_ROBOT_OPERATIONS)
		{
			return new CreateEvent($item->getEntityTypeId(), $analytics->getType());
		}

		return match ($analytics->getEvent())
		{
			Dictionary::EVENT_ENTITY_COPY => CopyEvent::createDefault($item->getEntityTypeId()),
			Dictionary::EVENT_ENTITY_CONVERT => ConvertEvent::createDefault($item->getEntityTypeId()),
			default => AddEvent::createDefault($item->getEntityTypeId()),
		};
	}

	private static function fillMissingCreatedItemId(AbstractBuilder $event, Item $item, Result $result): void
	{
		$itemId = $item->getId();
		if (!$result->isSuccess() || $itemId <= 0 || !($event instanceof AddEvent) || $event->getP2() !== [])
		{
			return;
		}

		$event->setP2('id', (string)$itemId);
	}

	private static function completeKanbanEvent(
		AbstractBuilder $event,
		AnalyticsEventDto $analytics,
		Item $item,
		Context $context,
		Result $result,
	): void
	{
		if (
			!$result->isSuccess()
			|| $item->getId() <= 0
			|| $context->getItemOption('KANBAN_CREATE_ORIGIN') !== true
			|| $analytics->getEvent() !== Dictionary::EVENT_ENTITY_CREATE
			|| $analytics->getSubSection() !== Dictionary::SUB_SECTION_KANBAN
			|| !in_array($analytics->getElement(), ['quick_button', 'fill_required_fields_popup'], true)
			|| !($event instanceof AddEvent)
		)
		{
			return;
		}

		$event
			->setSection(
				Dictionary::getSectionByEntityType($item->getEntityTypeId(), $item->getCategoryId()),
			)
			->setP2('id', (string)$item->getId())
			->clearP4()
		;
	}
}
