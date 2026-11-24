<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentItemDto;

final class ShipmentItemMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return ShipmentItemDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'basketId' => ['BASKET_ID', self::INT],
			'quantity' => ['QUANTITY', self::FLOAT],
			'reservedQuantity' => ['RESERVED_QUANTITY', self::FLOAT],
			'xmlId' => ['XML_ID', self::STRING],
			'dateInsert' => ['DATE_INSERT', self::DATE],
		];
	}
}
