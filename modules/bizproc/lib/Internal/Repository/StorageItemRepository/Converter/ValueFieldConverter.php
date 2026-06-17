<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\StorageItemRepository\Converter;

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
		];

		foreach ($converters as $converter)
		{
			self::$converters[$converter::getType()] = $converter;
		}
	}
}
