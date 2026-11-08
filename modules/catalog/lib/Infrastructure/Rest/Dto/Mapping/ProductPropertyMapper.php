<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductPropertyDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class ProductPropertyMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ProductPropertyDto::class);
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

	/**
	 * @param array<string, mixed> $rawItem
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function mapItem(array $rawItem, array $fields, bool $emptyFields): ProductPropertyDto
	{
		$dto = new ProductPropertyDto();

		if ($this->shouldMap('id', $fields, $emptyFields))
		{
			$id = $rawItem['id'] ?? $rawItem['ID'] ?? null;
			$dto->id = $id !== null ? (int)$id : null;
		}

		if ($this->shouldMap('iblockId', $fields, $emptyFields))
		{
			$iblockId = $rawItem['iblockId'] ?? $rawItem['IBLOCK_ID'] ?? null;
			$dto->iblockId = $iblockId !== null ? (int)$iblockId : null;
		}

		if ($this->shouldMap('name', $fields, $emptyFields))
		{
			$name = $rawItem['name'] ?? $rawItem['NAME'] ?? null;
			$dto->name = $name !== null ? (string)$name : null;
		}

		if ($this->shouldMap('sort', $fields, $emptyFields))
		{
			$sort = $rawItem['sort'] ?? $rawItem['SORT'] ?? null;
			$dto->sort = $sort !== null ? (int)$sort : null;
		}

		if ($this->shouldMap('code', $fields, $emptyFields))
		{
			$code = $rawItem['code'] ?? $rawItem['CODE'] ?? null;
			$dto->code = $code !== null ? (string)$code : null;
		}

		if ($this->shouldMap('multiple', $fields, $emptyFields))
		{
			$multiple = $rawItem['multiple'] ?? $rawItem['MULTIPLE'] ?? null;
			$dto->multiple = $multiple !== null ? (string)$multiple : null;
		}

		if ($this->shouldMap('isRequired', $fields, $emptyFields))
		{
			$isRequired = $rawItem['isRequired'] ?? $rawItem['IS_REQUIRED'] ?? null;
			$dto->isRequired = $isRequired !== null ? (string)$isRequired : null;
		}

		if ($this->shouldMap('controlId', $fields, $emptyFields))
		{
			$controlId = $rawItem['controlId'] ?? $rawItem['CONTROL_ID'] ?? null;
			$dto->controlId = $controlId !== null ? (string)$controlId : null;
		}

		return $dto;
	}

	/**
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function shouldMap(string $fieldName, array $fields, bool $emptyFields): bool
	{
		if ($emptyFields)
		{
			return true;
		}

		return in_array($fieldName, $fields, true) || array_key_exists($fieldName, $fields);
	}
}
