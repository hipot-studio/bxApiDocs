<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\TradeBindingMapper;

/**
 * Binding of an order to a trading platform.
 */
#[MappedBy(TradeBindingMapper::class)]
class TradeBindingDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_ID', null, null, __FILE__))]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_TRADING_PLATFORM_ID', null, null, __FILE__))]
	public ?int $tradingPlatformId;

	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_TRADING_PLATFORM_XML_ID', null, null, __FILE__))]
	public ?string $tradingPlatformXmlId;

	#[Editable(['add', 'update'])]
	#[FixedAtCreation]
	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_EXTERNAL_ORDER_ID', null, null, __FILE__))]
	public ?string $externalOrderId;

	// The parameters of a platform are the platform's own shape: the column is serialized and the
	// platforms that fill it write a structure, so the field carries one instead of flattening it.
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_PARAMS', null, null, __FILE__))]
	public string|array|null $params;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_TRADE_BINDING_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;
}
