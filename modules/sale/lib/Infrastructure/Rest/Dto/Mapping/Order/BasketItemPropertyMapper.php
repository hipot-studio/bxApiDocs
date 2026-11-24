<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemPropertyDto;

final class BasketItemPropertyMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return BasketItemPropertyDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'basketItemId' => ['BASKET_ID', self::INT],
			'name' => ['NAME', self::STRING],
			'code' => ['CODE', self::STRING],
			'value' => ['VALUE', self::STRING],
			'sort' => ['SORT', self::INT],
			'xmlId' => ['XML_ID', self::STRING],
		];
	}
}
