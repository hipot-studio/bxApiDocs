<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\SectionDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

/**
 * Maps \CIBlockSection::GetList() rows to {@see SectionDto}
 * and cuts the result down to the client select.
 */
final class SectionMapper extends Mapper
{
	/** Domain row key => DTO property */
	private const FIELD_MAP = [
		'ID' => 'id',
		'IBLOCK_ID' => 'iblockId',
		'NAME' => 'name',
		'IBLOCK_SECTION_ID' => 'iblockSectionId',
		'ACTIVE' => 'active',
		'CODE' => 'code',
		'XML_ID' => 'xmlId',
		'SORT' => 'sort',
		'DESCRIPTION' => 'description',
		'DESCRIPTION_TYPE' => 'descriptionType',
	];

	private const INT_PROPERTIES = ['id', 'iblockId', 'iblockSectionId', 'sort'];

	/**
	 * @param list<array<string, mixed>> $items
	 * @param list<string>|array<string, mixed> $fields
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(SectionDto::class);

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
	private function mapRow(array $row, array $fields): SectionDto
	{
		$dto = new SectionDto();
		$emptyFields = $fields === [];

		foreach (self::FIELD_MAP as $rowKey => $property)
		{
			if (!$emptyFields && !in_array($property, $fields, true) && !array_key_exists($property, $fields))
			{
				continue;
			}

			$value = $row[$rowKey] ?? null;
			$dto->{$property} = in_array($property, self::INT_PROPERTIES, true) ? (int)$value : (string)$value;
		}

		return $dto;
	}
}
