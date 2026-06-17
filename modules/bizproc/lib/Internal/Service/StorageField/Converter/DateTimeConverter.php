<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

use Bitrix\Bizproc\BaseType\Value;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Type\DateTime;

class DateTimeConverter extends DateConverter
{
	protected const FORMAT = 'Y-m-d H:i:s';

	public static function getType(): string
	{
		return FieldType::DATETIME;
	}

	public function toStorage(mixed $value): mixed
	{
		if (!isset($value) || $value === '')
		{
			return $value;
		}

		$timestamp = $this->resolveTimestamp($value);

		if ($timestamp !== null)
		{
			return DateTime::createFromTimestamp($timestamp)->format(static::FORMAT);
		}

		return null;
	}

	protected function createValueObject(int $timestamp): Value\Date
	{
		return new Value\DateTime($timestamp);
	}
}
