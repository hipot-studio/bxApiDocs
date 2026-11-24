<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\BasketItemPropertyMapper;
use Bitrix\Sale\Infrastructure\Rest\Service\FieldAccessPolicy;

/**
 * Property of a basket item.
 * One data type serves the element nested in a position and the resource of its own.
 */
#[MappedBy(BasketItemPropertyMapper::class)]
#[FieldAccess(FieldAccessPolicy::class)]
class BasketItemPropertyDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_ID', null, null, __FILE__))]
	public ?int $id;

	// Read only: the position is named beside the fields, and a nested element has no place to name it.
	#[Filterable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_BASKET_ITEM_ID', null, null, __FILE__))]
	public ?int $basketItemId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_NAME', null, null, __FILE__))]
	public ?string $name;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_CODE', null, null, __FILE__))]
	public ?string $code;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_VALUE', null, null, __FILE__))]
	public ?string $value;

	// The order of the collection is the order of this field, so it is the one a caller sorts by.
	#[Editable(['add', 'update'])]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_SORT', null, null, __FILE__))]
	public ?int $sort;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTY_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;
}
