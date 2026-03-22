<?php

namespace Bitrix\Crm\RepeatSale\Service\Action;

use Bitrix\Crm\Copilot\AiQueueBuffer\Controller\AiQueueBufferController;
use Bitrix\Crm\Copilot\AiQueueBuffer\Entity\AiQueueBufferItem;
use Bitrix\Crm\Copilot\AiQueueBuffer\Provider\ScreeningRepeatSaleItemProvider;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\Segment\SegmentItem;
use Bitrix\Crm\RepeatSale\Service\Context;
use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class AddToAiScreeningAction implements ActionInterface
{
	public function process(
		Item $clientItem,
		int $assignmentUserId,
		?Result $prevActionResult = null,
		?Context $context = null,
		?SegmentItem $segmentItem = null,
	): Result
	{
		if ($segmentItem?->getId() <= 0)
		{
			return (new Result())->addError(new Error('Unknown segmentItem'));
		}

		RepeatSaleAiScreeningTable::addInsertIgnore([
			'OWNER_TYPE_ID' => $clientItem->getEntityTypeId(),
			'OWNER_ID' => $clientItem->getId(),
			'SEGMENT_ID' => $segmentItem?->getId(),
			'PARAMS' => [
				'assignmentUserId' => $assignmentUserId,
			],
		]);

		return AiQueueBufferController::getInstance()->add(
			AiQueueBufferItem::createFromEntityFields([
				'PROVIDER_ID' => ScreeningRepeatSaleItemProvider::getId(),
				'PROVIDER_DATA' => [
					'clientEntityId' => $clientItem->getId(),
					'clientEntityTypeId' => $clientItem->getEntityTypeId(),
					'segmentId' => $segmentItem?->getId() ?? 0,
					'clientIdentifiers' => $this->getClientIdentifiers($clientItem),
				],
			]),
		);
	}

	private function getClientIdentifiers(Item $item): array
	{
		$clientIdentifiers = [];
		if ($item->getCompanyId())
		{
			$clientIdentifiers[] = new ItemIdentifier(CCrmOwnerType::Company, $item->getCompanyId());
		}

		if ($item->getContactId())
		{
			$clientIdentifiers[] = new ItemIdentifier(CCrmOwnerType::Contact, $item->getContactId());
		}

		return $clientIdentifiers;
	}
}
