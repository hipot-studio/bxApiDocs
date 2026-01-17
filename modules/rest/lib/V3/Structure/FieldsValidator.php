<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

class FieldsValidator
{
	public static function validateTypeAndValue(?string $type, mixed $value): bool
	{
		return match ($type)
		{
			'int' => is_int($value),
			'float' => is_float($value),
			'string' => is_string($value),
			'bool' => is_bool($value),
			'array' => is_array($value),
			DateTime::class => $value instanceof DateTime,
			Date::class => $value instanceof Date,
			default => false,
			null => true,
		};
	}
}
