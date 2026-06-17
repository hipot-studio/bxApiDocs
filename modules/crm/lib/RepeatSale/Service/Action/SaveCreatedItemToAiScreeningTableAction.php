<?php

namespace Bitrix\Crm\RepeatSale\Service\Action;

use Bitrix\Crm\Item;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Context;
use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Main\Result;

final class SaveCreatedItemToAiScreeningTableAction implements ActionInterface
{
	public function process(
		Item $clientItem,
		int $assignmentUserId,
		?Result $prevActionResult = null,
		?Context $context = null,
		?SegmentItem $segmentItem = null,
	): Result
	{
		if (!$prevActionResult?->isSuccess())
		{
			return $prevActionResult;
		}

		$aiScreeningItem = RepeatSaleAiScreeningTable::getList([
			'filter' => [
				'=OWNER_ID' => $clientItem->getId(),
				'=OWNER_TYPE_ID' => $clientItem->getEntityTypeId(),
			],
			'limit' => 1,
		])->fetchObject();

		if ($aiScreeningItem !== null)
		{
			$createdItem = $prevActionResult->getData()['item'];

			$aiScreeningItem->setResultEntityTypeId($createdItem->getEntityTypeId() ?? 0);
			$aiScreeningItem->setResultEntityId($createdItem->getId() ?? 0);
			$aiScreeningItem->save();
		}

		return $prevActionResult;
	}

	private function getSegmentId(?SegmentItem $segmentItem): ?int
	{
		if ($segmentItem === null)
		{
			return null;
		}

		$baseSegmentCode = $segmentItem->getBaseSegmentCode();
		if ($baseSegmentCode)
		{
			$segmentController = RepeatSaleSegmentController::getInstance();
			$parentSegment = $segmentController->getList([
				'select' => ['ID'],
				'filter' => [
					'=CODE' => $baseSegmentCode,
				],
				'limit' => 1,
				'ttl' => 3600 * 24,
			])->current();

			return $parentSegment?->getId();
		}

		return $segmentItem->getId();
	}
}
