<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\PropertyValueMapper;

/**
 * Order property value.
 */
#[MappedBy(PropertyValueMapper::class)]
class PropertyValueDto extends Dto
{
	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_ID', null, null, __FILE__))]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_ORDER_PROPS_ID', null, null, __FILE__))]
	public ?int $orderPropsId;

	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_ORDER_PROPS_XML_ID', null, null, __FILE__))]
	public ?string $orderPropsXmlId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_NAME', null, null, __FILE__))]
	public ?string $name;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_CODE', null, null, __FILE__))]
	public ?string $code;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PROPERTY_VALUE_VALUE', null, null, __FILE__))]
	public string|array|null $value;
}
