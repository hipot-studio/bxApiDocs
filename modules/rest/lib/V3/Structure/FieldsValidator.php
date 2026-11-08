<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumDefinition;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumRegistry;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumType;
use Bitrix\Rest\V3\Dto\TypeHelper;

class FieldsValidator
{
	public static function validateDtoFieldValue(DtoField $field, mixed $value): bool
	{
		if ($field->getDynamicEnumProvider() !== null)
		{
			$definition = DynamicEnumRegistry::resolve($field->getDynamicEnumProvider());

			return self::validateDynamicEnumValue($definition, $value);
		}

		return self::validateTypeAndValue($field->getPropertyType(), $value);
	}

	public static function validateDynamicEnumValue(DynamicEnumDefinition $definition, mixed $value): bool
	{
		$typeOk = match ($definition->type)
		{
			DynamicEnumType::String => is_string($value),
			DynamicEnumType::Int => is_int($value),
		};

		return $typeOk && $definition->contains($value);
	}

	public static function validateTypeAndValue(?string $type, mixed $value): bool
	{
		if (TypeHelper::isBackedEnum($type))
		{
			return $value instanceof $type;
		}

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
