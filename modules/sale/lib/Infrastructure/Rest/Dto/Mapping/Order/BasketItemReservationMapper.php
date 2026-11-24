<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemReservationDto;

final class BasketItemReservationMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return BasketItemReservationDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'storeId' => ['STORE_ID', self::INT],
			'quantity' => ['QUANTITY', self::FLOAT],
			'dateReserve' => ['DATE_RESERVE', self::DATE],
			'dateReserveEnd' => ['DATE_RESERVE_END', self::DATE],
			'reservedBy' => ['RESERVED_BY', self::INT],
		];
	}
}
