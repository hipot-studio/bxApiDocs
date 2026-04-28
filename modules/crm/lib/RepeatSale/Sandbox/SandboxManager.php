<?php

namespace Bitrix\Crm\RepeatSale\Sandbox;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Feature;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Sandbox\Entity\RepeatSaleSandboxTable;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

final class SandboxManager
{
	use Singleton;

	public function add(int $jobId, ItemIdentifier $target): AddResult
	{
		if (!$this->isAvailableSandboxMode())
		{
			return (new AddResult())->addError(ErrorCode::getFeatureDisabledError());
		}

		return RepeatSaleSandboxTable::add([
			'JOB_ID' => $jobId,
			'ITEM_TYPE_ID' => $target->getEntityTypeId(),
			'ITEM_ID' => $target->getEntityId(),
		]);
	}

	public function appendPayloadData(int $jobId, array $payloadData): UpdateResult
	{
		if (!$this->isAvailableSandboxMode())
		{
			return (new UpdateResult())->addError(ErrorCode::getFeatureDisabledError());
		}

		$item = RepeatSaleSandboxTable::getList([
			'filter' => ['=JOB_ID' => $jobId],
			'limit' => 1,
		])->fetchObject();

		if (!$item)
		{
			return (new UpdateResult())->addError(ErrorCode::getNotFoundError());
		}

		$item->setPayload($payloadData);

		return $item->save();
	}

	public function isSuitableItem(
		SegmentItem $segmentItem,
		ItemIdentifier $itemIdentifier,
		?ItemIdentifier $clientIdentifier = null,
		?Date $date = new Date(),
	): bool
	{
		if (!$this->isAvailableSandboxMode())
		{
			return false;
		}

		// @todo Taking into account the AI segment, the retrieval and selection of the target element's ID will be more correct.
		$entityTypeId = $clientIdentifier?->getEntityTypeId() ?? $itemIdentifier->getEntityTypeId();
		$entityId = $clientIdentifier?->getEntityId() ?? $itemIdentifier->getEntityId();

		$segmentData = \Bitrix\Crm\RepeatSale\Segment\Collector\Factory::getInstance()
			->getCollector($segmentItem->getSegmentCode())
			?->setLimit(0)
			->setDate($date)
			->getSegmentData($entityTypeId)
		;

		$items = $segmentData->getItems();
		foreach ($items as $item)
		{
			if (
				$item->getId() === $entityId
				&& $item->getEntityTypeId() === $entityTypeId
			)
			{
				return true;
			}
		}

		return false;
	}

	public function getSuitableItems(SegmentItem $segmentItem, Date $fromDate, Date $toDate): array
	{
		if (!$this->isAvailableSandboxMode())
		{
			return [];
		}

		if ($fromDate > $toDate)
		{
			return [];
		}

		$entityTypeIds = [
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company,
		];

		$from = clone $fromDate;

		$result = [];
		while ($from <= $toDate)
		{
			foreach ($entityTypeIds as $entityTypeId)
			{
				$segmentData = \Bitrix\Crm\RepeatSale\Segment\Collector\Factory::getInstance()
					->getCollector($segmentItem->getSegmentCode())
					?->setLimit(0)
					->setDate($from)
					->getSegmentData($entityTypeId)
				;

				$items = $segmentData->getItems();
				foreach ($items as $item)
				{
					$result[] = new ItemIdentifier($item->getEntityTypeId(), $item->getId());
				}
			}

			$from->add('+1 day');
		}

		return $result;
	}

	public function isAvailableSandboxMode(): bool
	{
		return Feature::enabled(Feature\RepeatSaleSandbox::class);
	}
}
