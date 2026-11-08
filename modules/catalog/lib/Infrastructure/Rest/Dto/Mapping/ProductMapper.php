<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ProductDto;
use Bitrix\Catalog\Infrastructure\Rest\Support\ProductFieldsMapper;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use ReflectionProperty;

final class ProductMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ProductDto::class);
		$emptyFields = $fields === [];

		foreach ($items as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$dto = ProductFieldsMapper::rowToDto($row);
			$collection->add($emptyFields ? $dto : $this->filterDtoFields($dto, $fields));
		}

		return $collection;
	}

	/**
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function filterDtoFields(ProductDto $source, array $fields): ProductDto
	{
		$dto = new ProductDto();

		foreach ((new \ReflectionClass($source))->getProperties(ReflectionProperty::IS_PUBLIC) as $property)
		{
			$name = $property->getName();
			if (!$this->shouldMap($name, $fields))
			{
				continue;
			}
			if (!$property->isInitialized($source))
			{
				continue;
			}

			$dto->{$name} = $source->{$name};
		}

		return $dto;
	}

	/**
	 * @param list<string>|array<string, mixed> $fields
	 */
	private function shouldMap(string $fieldName, array $fields): bool
	{
		return in_array($fieldName, $fields, true) || array_key_exists($fieldName, $fields);
	}
}
