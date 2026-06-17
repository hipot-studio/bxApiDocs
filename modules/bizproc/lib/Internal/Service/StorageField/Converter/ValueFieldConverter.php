<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

class ValueFieldConverter
{
	private static array $converters = [];

	public static function toStorage(mixed $value, string $type): mixed
	{
		$converter = self::getConverter($type);

		if ($converter)
		{
			return $converter->toStorage($value);
		}

		return $value;
	}

	/**
	 * Converts the whole field value (single or multiple) into a flat array of storage-ready values.
	 * Handles cases where a single input element expands into multiple values (e.g. group_* → user IDs).
	 */
	public static function toStorageValues(mixed $value, string $type, bool $isMultiple): array
	{
		$values = $isMultiple ? (array)$value : [$value];
		$result = [];

		foreach ($values as $one)
		{
			if (\CBPHelper::isEmptyValue($one))
			{
				continue;
			}

			$storedValue = self::toStorage($one, $type);

			if (is_array($storedValue))
			{
				if (!$isMultiple)
				{
					$first = current($storedValue);
					if (!\CBPHelper::isEmptyValue($first))
					{
						$result[] = $first;
					}
				}
				else
				{
					foreach ($storedValue as $item)
					{
						if (!\CBPHelper::isEmptyValue($item))
						{
							$result[] = $item;
						}
					}
				}
			}
			elseif ($storedValue !== null)
			{
				$result[] = $storedValue;
			}
		}

		return $result;
	}

	public static function fromStorage(mixed $value, string $type): mixed
	{
		$converter = self::getConverter($type);

		if ($converter)
		{
			return $converter->fromStorage($value);
		}

		return $value;
	}

	private static function getConverter(string $type): ?TypeConverterInterface
	{
		if (!self::$converters)
		{
			self::registerConverters();
		}

		return self::$converters[$type] ?? null;
	}

	private static function registerConverters(): void
	{
		$converters = [
			new DateConverter(),
			new DateTimeConverter(),
			new BoolConverter(),
			new UserConverter(),
		];

		foreach ($converters as $converter)
		{
			self::$converters[$converter::getType()] = $converter;
		}
	}
}
