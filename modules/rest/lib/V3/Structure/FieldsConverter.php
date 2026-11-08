<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumDefinition;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumRegistry;
use Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumType;
use Bitrix\Rest\V3\Dto\TypeHelper;

class FieldsConverter
{
	public static function convertValueByDtoField(DtoField $field, mixed $value): mixed
	{
		if ($field->getDynamicEnumProvider() !== null)
		{
			return self::convertDynamicEnumValue($field->getDynamicEnumProvider(), $value);
		}

		return self::convertValueByType($field->getPropertyType(), $value);
	}

	public static function convertValueByType(?string $fieldType, mixed $value)
	{
		// `\Throwable` (not `\Exception`) is intentional: BackedEnum::tryFrom() can throw
		// `\TypeError` when the runtime value type does not match the enum's backing type
		// (e.g. passing a non-numeric string to an int-backed enum, see the explicit
		// is_int/is_string guard for BackedEnum below). TypeError extends \Error, not
		// \Exception, so the previous `catch (\Exception)` let it escape to the caller
		// as an HTTP 500 instead of degrading to "leave value as-is for downstream
		// validation to report a clean error".
		try
		{
			if ($fieldType === DateTime::class)
			{
				$correctDateTime = \DateTime::createFromFormat(DATE_ATOM, $value);
				if ($correctDateTime !== false)
				{
					return DateTime::createFromPhp($correctDateTime);
				}
			}
			elseif ($fieldType === Date::class)
			{
				$correctDate = \DateTime::createFromFormat('Y-m-d', $value);
				if ($correctDate !== false)
				{
					return Date::createFromPhp($correctDate);
				}
			}
			elseif (TypeHelper::isBackedEnum($fieldType))
			{
				if ($value instanceof $fieldType)
				{
					return $value;
				}
				if (self::isAssignableToEnumBackingType($fieldType, $value))
				{
					$enumValue = $fieldType::tryFrom($value);
					if ($enumValue !== null)
					{
						return $enumValue;
					}
				}
			}
		}
		catch (\Throwable)
		{
		}

		return $value;
	}

	private static function convertDynamicEnumValue(string $providerClass, mixed $value): mixed
	{
		return self::convertValueByDynamicEnumDefinition(
			DynamicEnumRegistry::resolve($providerClass),
			$value,
		);
	}

	private static function convertValueByDynamicEnumDefinition(DynamicEnumDefinition $definition, mixed $value): mixed
	{
		try
		{
			return match ($definition->type)
			{
				DynamicEnumType::String => is_string($value) || is_int($value) || is_float($value)
					? (string)$value
					: $value,
				DynamicEnumType::Int => is_int($value) || (is_string($value) && is_numeric($value))
					? (int)$value
					: $value,
			};
		}
		catch (\Throwable)
		{
			return $value;
		}
	}

	/**
	 * Checks that $value matches the backing type of $enumClass *before* we attempt
	 * tryFrom(). Without this guard, a non-numeric string fed into an int-backed
	 * enum's tryFrom() raises \TypeError, which is not catchable by `\Exception`
	 * and would surface as HTTP 500 instead of clean input-validation feedback.
	 *
	 * Numeric strings are accepted for int-backed enums — PHP itself accepts them
	 * in tryFrom() (verified: `IntEnum::tryFrom("5")` returns null cleanly, not a
	 * TypeError), so excluding them would be stricter than the language.
	 *
	 * @param class-string<\BackedEnum> $enumClass
	 */
	private static function isAssignableToEnumBackingType(string $enumClass, mixed $value): bool
	{
		try
		{
			$backingType = (new \ReflectionEnum($enumClass))->getBackingType()?->getName();
		}
		catch (\ReflectionException)
		{
			return false;
		}

		// PHP's BackedEnum::tryFrom() raises TypeError in exactly one shape:
		// non-numeric string fed into an int-backed enum. Every other scalar
		// combination (int into str-backed, numeric string into int-backed,
		// any mismatch) returns null cleanly. Mirror that exact contract so we
		// neither over-restrict (rejecting cases PHP handles) nor under-restrict
		// (letting through the one shape that explodes).
		return match ($backingType)
		{
			'int' => is_int($value) || (is_string($value) && is_numeric($value)),
			'string' => is_string($value) || is_int($value),
			default => false,
		};
	}
}
