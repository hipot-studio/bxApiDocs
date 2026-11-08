<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Iblock\Infrastructure\Rest\Dto\ElementDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class ElementMapper extends Mapper
{
	private const SCALAR_FIELDS = [
		'id' => 'ID',
		'iblockId' => 'IBLOCK_ID',
		'name' => 'NAME',
		'iblockSectionId' => 'IBLOCK_SECTION_ID',
	];

	private const PROPERTY_VALUE_KEYS = [
		'ID' => 'id',
		'VALUE' => 'value',
		'DESCRIPTION' => 'description',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ElementDto::class);
		$emptyFields = $fields === [];

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapItem($rawItem, $fields, $emptyFields));
		}

		return $collection;
	}

	private function mapItem(array $rawItem, array $fields, bool $emptyFields): ElementDto
	{
		$dto = new ElementDto();

		foreach (self::SCALAR_FIELDS as $dtoField => $dataField)
		{
			if ($this->shouldMapScalar($dtoField, $fields, $emptyFields))
			{
				$dto->{$dtoField} = $this->mapScalarValue($dtoField, $rawItem[$dataField] ?? null);
			}
		}

		$properties = $this->extractProperties($rawItem);
		if (in_array('properties', $fields, true) || ($emptyFields && $properties !== []))
		{
			$dto->properties = $properties;
		}

		return $dto;
	}

	private function shouldMapScalar(string $dtoField, array $fields, bool $emptyFields): bool
	{
		if ($emptyFields)
		{
			return $dtoField !== 'properties';
		}

		return in_array($dtoField, $fields, true);
	}

	private function mapScalarValue(string $dtoField, mixed $value): int|string|null
	{
		if ($dtoField === 'name')
		{
			return $value !== null ? (string)$value : null;
		}

		if ($value === null || $value === '')
		{
			return null;
		}

		$intValue = (int)$value;

		// Bitrix stores "no section" as 0; expose it as null in V3.
		if ($dtoField === 'iblockSectionId' && $intValue === 0)
		{
			return null;
		}

		return $intValue;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function extractProperties(array $rawItem): array
	{
		$properties = [];
		$scalarOrmFields = array_values(self::SCALAR_FIELDS);

		foreach ($rawItem as $key => $value)
		{
			if (!is_string($key) || in_array($key, $scalarOrmFields, true))
			{
				continue;
			}

			if (!is_array($value))
			{
				continue;
			}

			$normalized = $this->normalizePropertyValue($value);
			if ($normalized !== null)
			{
				$properties[$key] = $normalized;
			}
		}

		return $properties;
	}

	/**
	 * @param array $value
	 * @return array|list<array>|null
	 */
	private function normalizePropertyValue(array $value): array|null
	{
		if ($this->looksLikePropertyRow($value))
		{
			return $this->mapPropertyRow($value);
		}

		$rows = [];
		foreach ($value as $item)
		{
			if (is_array($item) && $this->looksLikePropertyRow($item))
			{
				$rows[] = $this->mapPropertyRow($item);
			}
		}

		return $rows === [] ? null : $rows;
	}

	private function looksLikePropertyRow(array $value): bool
	{
		return array_key_exists('VALUE', $value)
			|| array_key_exists('ID', $value)
			|| array_key_exists('DESCRIPTION', $value);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function mapPropertyRow(array $row): array
	{
		$mapped = [];
		foreach (self::PROPERTY_VALUE_KEYS as $ormKey => $dtoKey)
		{
			if (array_key_exists($ormKey, $row))
			{
				$mapped[$dtoKey] = $row[$ormKey];
			}
		}

		return $mapped;
	}
}
