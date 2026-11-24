<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\ShipmentItemMapper;

/**
 * Item of a shipment. Store data of the item is not exposed, as in V1.
 */
#[MappedBy(ShipmentItemMapper::class)]
class ShipmentItemDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_ID', null, null, __FILE__))]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_BASKET_ID', null, null, __FILE__))]
	public ?int $basketId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_QUANTITY', null, null, __FILE__))]
	public ?float $quantity;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_RESERVED_QUANTITY', null, null, __FILE__))]
	public ?float $reservedQuantity;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ITEM_DATE_INSERT', null, null, __FILE__))]
	public ?DateTime $dateInsert;
}
