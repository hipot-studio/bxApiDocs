<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\CatalogDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Maps \CCatalog::GetList() / \CCatalog::GetByID() rows to {@see CatalogDto}
 * and cuts the result down to the client select.
 */
final class CatalogMapper extends Mapper
{
	/** Domain row key => DTO property */
	private const FIELD_MAP = [
		'ID' => 'id',
		'IBLOCK_ID' => 'iblockId',
		'YANDEX_EXPORT' => 'yandexExport',
		'SUBSCRIPTION' => 'subscription',
		'VAT_ID' => 'vatId',
		'PRODUCT_IBLOCK_ID' => 'productIblockId',
		'SKU_PROPERTY_ID' => 'skuPropertyId',
		'IBLOCK_TYPE_ID' => 'iblockTypeId',
		'LID' => 'lid',
		'NAME' => 'name',
	];

	private const INT_PROPERTIES = ['id', 'iblockId', 'vatId', 'productIblockId', 'skuPropertyId'];

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<string>|array<string, mixed> $fields
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(CatalogDto::class);

		foreach ($items as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$collection->add($this->mapRow($row, $fields));
		}

		return $collection;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function mapRow(array $row, array $fields): CatalogDto
	{
		$dto = new CatalogDto();
		$emptyFields = $fields === [];

		foreach (self::FIELD_MAP as $rowKey => $property)
		{
			if (!$emptyFields && !in_array($property, $fields, true) && !array_key_exists($property, $fields))
			{
				continue;
			}

			$value = $this->readValue($row, $rowKey);
			$dto->{$property} = in_array($property, self::INT_PROPERTIES, true) ? (int)$value : (string)$value;
		}

		return $dto;
	}

	/**
	 * Trade catalog id is the iblock id, so ID and IBLOCK_ID substitute for each other
	 * when the domain select returned only one of them.
	 *
	 * @param array<string, mixed> $row
	 */
	private function readValue(array $row, string $rowKey): mixed
	{
		$value = $row[$rowKey] ?? null;
		if ($value !== null)
		{
			return $value;
		}

		return match ($rowKey)
		{
			'ID' => $row['IBLOCK_ID'] ?? null,
			'IBLOCK_ID' => $row['ID'] ?? null,
			default => null,
		};
	}
}
