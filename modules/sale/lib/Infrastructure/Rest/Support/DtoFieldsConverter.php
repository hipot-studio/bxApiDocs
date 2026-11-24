<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * DTO to a domain field array.
 *
 * The whole partial-write contract rests on one property of PHP: a typed property that was never
 * assigned stays uninitialized, and `get_object_vars()` does not report it. A property assigned
 * `null` is reported. That is the only way to tell "the client did not send the key" from "the
 * client sent an empty value": a check like `isset($array[$key])` merges the two cases, which is
 * how the V1 defect wiping trade platform bindings works.
 */
final class DtoFieldsConverter
{
	/**
	 * Initialized public properties of a DTO, null values included.
	 *
	 * @return array<string, mixed>
	 */
	public static function getInitializedValues(Dto $dto): array
	{
		return get_object_vars($dto);
	}

	/**
	 * @param array<string, string> $columnMap DTO property => domain column
	 * @param list<string> $skip properties handled by the caller and left out of the result
	 * @return array<string, mixed>
	 */
	public static function toDomainFields(Dto $dto, array $columnMap, array $skip = []): array
	{
		$fields = [];
		foreach (self::getInitializedValues($dto) as $property => $value)
		{
			if (in_array($property, $skip, true) || !isset($columnMap[$property]))
			{
				continue;
			}

			$fields[$columnMap[$property]] = self::toDomainValue($value);
		}

		return $fields;
	}

	/**
	 * The domain keeps flags as Y/N chars; the transport contract is boolean.
	 */
	private static function toDomainValue(mixed $value): mixed
	{
		if (is_bool($value))
		{
			return $value ? 'Y' : 'N';
		}

		return $value;
	}
}
