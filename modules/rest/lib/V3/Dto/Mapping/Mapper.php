<?php

namespace Bitrix\Rest\V3\Dto\Mapping;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\LogicException;

abstract class Mapper
{
	final public function mapOne(mixed $item, array $fields = []): Dto
	{
		$collection = $this->mapCollection([$item], $fields);

		return $collection->first();
	}

	abstract public function mapCollection(array $items, array $fields = []): DtoCollection;

	/**
	 * Maps a single raw nested value to a Dto via its #[MappedBy] mapper.
	 *
	 * @param string     $dtoClass  Target DTO class name.
	 * @param mixed      $rawValue  Raw data for the nested entity.
	 * @param array      $fields    Fields to select for the nested DTO (empty = all fields).
	 */
	protected function resolveNested(string $dtoClass, mixed $rawValue, array $fields = []): ?Dto
	{
		if ($rawValue === null)
		{
			return null;
		}

		$mapper = MapperRegistry::getForDto($dtoClass);
		if ($mapper === null)
		{
			throw new LogicException(
				new \Exception("Cannot resolve nested mapper: DTO class {$dtoClass} has no #[MappedBy] attribute.")
			);
		}

		return $mapper->mapOne($rawValue, $fields);
	}

	/**
	 * Maps an array of raw nested values to a DtoCollection via the nested DTO's #[MappedBy] mapper.
	 *
	 * @param string $dtoClass  Target DTO class name.
	 * @param array  $rawItems  Raw data items.
	 * @param array  $fields    Fields to select for each nested DTO (empty = all fields).
	 */
	protected function resolveNestedCollection(string $dtoClass, array $rawItems, array $fields = []): DtoCollection
	{
		$mapper = MapperRegistry::getForDto($dtoClass);
		if ($mapper === null)
		{
			throw new LogicException(
				new \Exception("Cannot resolve nested mapper: DTO class {$dtoClass} has no #[MappedBy] attribute.")
			);
		}

		return $mapper->mapCollection($rawItems, $fields);
	}

	/**
	 * Automatically maps nested DTO relation fields from raw data using #[MappedBy] mappers.
	 *
	 * @param Dto    $dto          The parent DTO instance being populated.
	 * @param array  $rawItem      Raw data for the parent DTO.
	 * @param array  $fields       Fields requested for the parent DTO (used to skip unneeded relations).
	 * @param array  $nestedFields Per-relation field lists: ['relationPropertyName' => ['field1', 'field2']].
	 *                             An empty sub-array means "all fields" for that nested DTO.
	 */
	protected function autoMapRelations(Dto $dto, array $rawItem, array $fields = [], array $nestedFields = []): void
	{
		foreach ($dto->getFields() as $field)
		{
			$relation = $field->getRelation();
			if ($relation === null)
			{
				continue; // Not a relation field — skip
			}

			$fieldName = $field->getPropertyName();

			// Skip if not in the parent's requested fields
			if (
				!empty($fields)
				&& !in_array($fieldName, $fields, true)
				&& !array_key_exists($fieldName, $fields)
			)
			{
				continue;
			}

			// Skip if raw data doesn't contain this key
			if (!array_key_exists($fieldName, $rawItem))
			{
				continue;
			}

			// Resolve fields for this specific nested DTO (empty = no filtering)
			$structuredChild = is_array($fields[$fieldName] ?? null) ? $fields[$fieldName] : [];
			$childFields = $nestedFields[$fieldName] ?? $structuredChild;

			if ($relation->multiple)
			{
				// RelationToMany: DtoCollection field
				$elementType = $field->getElementType();
				if ($elementType !== null && is_subclass_of($elementType, Dto::class))
				{
					$rawNested = $rawItem[$fieldName];
					$dto->{$fieldName} = is_array($rawNested)
						? $this->resolveNestedCollection($elementType, $rawNested, $childFields)
						: new DtoCollection($elementType);
				}
			}
			else
			{
				// RelationToOne: direct Dto subclass field
				$nestedDtoClass = $field->getPropertyType();
				if (is_subclass_of($nestedDtoClass, Dto::class))
				{
					$dto->{$fieldName} = $this->resolveNested($nestedDtoClass, $rawItem[$fieldName], $childFields);
				}
			}
		}
	}
}
