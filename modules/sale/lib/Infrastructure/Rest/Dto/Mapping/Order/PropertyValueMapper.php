<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PropertyValueDto;

/**
 * The value of a multiple property is an array, of a plain one a string: both forms are carried
 * through untouched.
 */
final class PropertyValueMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return PropertyValueDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'orderPropsId' => ['ORDER_PROPS_ID', self::INT],
			'orderPropsXmlId' => ['ORDER_PROPS_XML_ID', self::STRING],
			'name' => ['NAME', self::STRING],
			'code' => ['CODE', self::STRING],
			'value' => ['VALUE', self::RAW],
		];
	}
}
