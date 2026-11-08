<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\Autorun\Dto\WhatsappMessageData;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\MessageSender\MassWhatsApp\SendItem;
use Bitrix\Crm\MessageSender\MassWhatsApp\TemplateParams;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Result;

final class WhatsAppMessage extends Base
{
	protected function prepareData(
		string $hash,
		string $gridId,
		int $entityTypeId,
		array $filter,
		array $params,
		Factory $factory
	): Dto\PreparedData
	{
		return new WhatsappMessageData([
			'hash' => $hash,
			'gridId' => $gridId,
			'entityTypeId' => $entityTypeId,
			'filter' => $filter,
			'messageBody' => $params['extras']['messageBody'] ?? null,
			'messageTemplate' => $params['extras']['messageTemplate'] ?? null,
			'fromPhone' => $params['extras']['fromPhone'] ?? null,
		]);
	}

	protected function getPreparedDataDtoClass(): string
	{
		return WhatsappMessageData::class;
	}

	protected function getSelect(): array
	{
		return [Item::FIELD_NAME_ID];
	}

	/**
	 * The right to send is checked per item, and every such check reads the permission attributes of that
	 * item from the database. One warm-up per page keeps that from becoming a query per item.
	 *
	 * @param Item[] $itemsToProcess
	 */
	protected function onBeforeItemsProcessing(Factory $factory, array $itemsToProcess): void
	{
		Container::getInstance()->getUserPermissions()->item()->preloadPermissionAttributes(
			$factory->getEntityTypeId(),
			array_map(static fn(Item $item) => $item->getId(), $itemsToProcess),
		);
	}

	/**
	 * @param Factory $factory
	 * @param Item $item
	 * @param WhatsappMessageData $data
	 * @return Result
	 */
	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		$identifier = ItemIdentifier::createByItem($item);

		if (!Container::getInstance()->getUserPermissions()->messageSender()->canSendItemIdentifier($identifier))
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}

		$sendItemService = SendItem::getInstance();

		return $sendItemService->execute(
			$identifier,
			new TemplateParams($data->messageBody, $data->messageTemplate, $data->fromPhone)
		);
	}
}
