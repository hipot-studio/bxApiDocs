<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository\Converter;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\FieldType;

class DateTimeConverter extends DateConverter
{
	protected const FORMAT = 'Y-m-d H:i:s';

	public static function getType(): string
	{
		return FieldType::DATETIME;
	}

	protected function createValueObject(int $timestamp): Value\Date
	{
		return new Value\DateTime($timestamp);
	}
}
