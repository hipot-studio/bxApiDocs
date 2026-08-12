<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Trait;

use BackedEnum;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\AbstractEntity;
use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;
use Bitrix\Timeman\V2\Internal\Entity\ValueObjectInterface;

trait MapTypeTrait
{
	public static function mapInteger(array $props, string $key, ?int $default = null): ?int
	{
		if (!array_key_exists($key, $props))
		{
			return $default ?? null;
		}

		$value = $props[$key];
		if (!is_numeric($value))
		{
			return null;
		}

		return (int)$value;
	}

	public static function mapString(array $props, string $key, ?string $default = null): ?string
	{
		if (!array_key_exists($key, $props))
		{
			return $default ?? null;
		}

		$value = $props[$key];
		if (!is_string($value))
		{
			return null;
		}

		return $value;
	}

	public static function mapPlainText(array $props, string $key, ?string $default = null): ?string
	{
		$value = self::mapString($props, $key, $default);
		if ($value === null || $value === '')
		{
			return $value;
		}

		$value = preg_replace('#<br\b[^>]*>#i', "\n", $value);
		$value = preg_replace('/<[^>]+>/', ' ', (string)$value);
		$value = strip_tags((string)$value);
		$value = preg_replace('/\[\/?[a-z][^\]]*\]/iu', ' ', $value);
		$value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$lines = array_map(
			static fn(string $line): string => trim(preg_replace('/\s+/u', ' ', $line)),
			explode("\n", $value),
		);

		return implode("\n", array_filter($lines, static fn(string $line): bool => $line !== ''));
	}

	/**
	 * @param class-string<BackedEnum> $enumClass
	 */
	public static function mapBackedEnum(
		array $props,
		string $key,
		string $enumClass,
		?BackedEnum $default = null,
	): ?BackedEnum
	{
		if (!isset($props[$key]))
		{
			return $default instanceof $enumClass ? $default : null;
		}

		$value = $props[$key];
		if ($value instanceof $enumClass)
		{
			/** @var BackedEnum $value */
			return $value;
		}

		$value = is_string($value) ? $value : static::mapInteger($props, $key);

		return $value !== null ? $enumClass::tryFrom($value) : null;
	}

	public static function mapBool(array $props, string $key, ?bool $default = null): ?bool
	{
		if (!array_key_exists($key, $props))
		{
			return $default ?? null;
		}

		$value = $props[$key];
		if (!is_bool($value))
		{
			return null;
		}

		return $value;
	}

	public static function mapArray(array $props, string $key, ?array $default = null): ?array
	{
		if (!array_key_exists($key, $props))
		{
			return $default ?? null;
		}

		$value = $props[$key];

		return is_array($value) ? $value : null;
	}

	/**
	 * @param class-string<AbstractEntity> $entityClass
	 */
	public static function mapEntity(
		array $props,
		string $key,
		string $entityClass,
		?AbstractEntity $default = null,
	): ?AbstractEntity
	{
		if (!isset($props[$key]))
		{
			if (isset($props["{$key}Id"]))
			{
				return $entityClass::mapFromId($props["{$key}Id"]);
			}

			return $default instanceof $entityClass ? $default : null;
		}

		$value = $props[$key];
		if ($value instanceof $entityClass)
		{
			/** @var AbstractEntity $value */
			return $value;
		}

		if (!is_array($value))
		{
			return null;
		}

		return $entityClass::mapFromArray($value);
	}

	/**
	 * @param class-string<ValueObjectInterface> $valueObjectClass
	 */
	public static function mapValueObject(
		array $props,
		string $key,
		string $valueObjectClass,
		?ValueObjectInterface $default = null,
	): ?ValueObjectInterface
	{
		if (!isset($props[$key]))
		{
			return $default instanceof $valueObjectClass ? $default : null;
		}

		$value = $props[$key];
		if ($value instanceof $valueObjectClass)
		{
			/** @var ValueObjectInterface $value */
			return $value;
		}

		if (!is_array($value))
		{
			return null;
		}

		return $valueObjectClass::mapFromArray($value);
	}

	/**
	 * @param class-string<AbstractEntityCollection> $entityCollectionClass
	 */
	public static function mapEntityCollection(
		array $props,
		string $key,
		string $entityCollectionClass,
		?AbstractEntityCollection $default = null,
	): ?AbstractEntityCollection
	{
		if (!isset($props[$key]))
		{
			if (isset($props["{$key}Ids"]))
			{
				return $entityCollectionClass::mapFromIds($props["{$key}Ids"]);
			}

			return $default instanceof $entityCollectionClass ? $default : null;
		}

		$value = $props[$key];
		if ($value instanceof $entityCollectionClass)
		{
			/** @var AbstractEntityCollection $value */
			return $value;
		}

		if (!is_array($value))
		{
			return null;
		}

		return $entityCollectionClass::mapFromArray($value);
	}

	public static function mapDateTime(array $props, string $key, ?DateTime $default = null): ?DateTime
	{
		if (!isset($props[$key]))
		{
			return $default ?? null;
		}

		$value = $props[$key];

		return $value instanceof DateTime ? $value : null;
	}
}
