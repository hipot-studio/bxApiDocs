<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\BasketItemReservationMapper;

/**
 * Stock reservation of a basket item. Read-only: V1 accepts no reservations on input.
 */
#[MappedBy(BasketItemReservationMapper::class)]
class BasketItemReservationDto extends Dto
{
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_ID', null, null, __FILE__))]
	public ?int $id;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_STORE_ID', null, null, __FILE__))]
	public ?int $storeId;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_QUANTITY', null, null, __FILE__))]
	public ?float $quantity;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_DATE_RESERVE', null, null, __FILE__))]
	public ?DateTime $dateReserve;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_DATE_RESERVE_END', null, null, __FILE__))]
	public ?DateTime $dateReserveEnd;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATION_RESERVED_BY', null, null, __FILE__))]
	public ?int $reservedBy;
}
