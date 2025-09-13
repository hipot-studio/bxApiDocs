<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Trait;

use BackedEnum;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;

trait MapTypeTrait
{
	private static function mapInteger(array $props, string $key): ?int
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_numeric($value))
		{
			return null;
		}

		return (int)$value;
	}

	private static function mapString(array $props, string $key): ?string
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_string($value))
		{
			return null;
		}

		return $value;
	}

	/**
	 * @param class-string<BackedEnum> $enumClass
	 */
	private static function mapBackedEnum(array $props, string $key, string $enumClass): ?BackedEnum
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = is_string($props[$key]) ? $props[$key] : static::mapInteger($props, $key);

		return $value !== null ? $enumClass::tryFrom($value) : null;
	}

	private static function mapBool(array $props, string $key): ?bool
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_bool($value))
		{
			return null;
		}

		return $value;
	}

	private static function mapArray(array $props, string $key): ?array
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_array($value))
		{
			return null;
		}

		return $value;
	}

	/**
	 * @param class-string<AbstractEntity> $entityClass
	 */
	private static function mapEntity(array $props, string $key, string $entityClass): ?object
	{
		if (!isset($props[$key]))
		{
			return null;
		}

		$value = $props[$key];
		if (!is_array($value))
		{
			return null;
		}

		return $entityClass::mapFromArray($value);
	}
}