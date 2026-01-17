<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Relation;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;

/**
 * Used as list of DTO fields to return
 */
final class SelectStructure extends Structure
{
	use UserFieldsTrait;

	/** @var string[] $items */
	protected array $items = [];

	protected bool $multiple = false;

	protected array $relationFields = [];

	public static function create(mixed $value, string $dtoClass, ?Request $request = null): self
	{
		$structure = new self();

		$value = (array)$value;

		$dto = self::getDto($dtoClass);

		$fields = $dto->getFields();

		$availableFields = [];

		if ($request->getOptions()['scope'])
		{
			$availableFields = $request->getOptions()['scope']->fields;
		}

		if (!empty($value))
		{
			foreach ($value as $item)
			{
				if (!is_array($item))
				{
					if (strpos($item, '.') === false)
					{
						if (!isset($fields[$item]))
						{
							throw new UnknownDtoPropertyException($dto->getShortName(), $item);
						}

						if (!empty($availableFields) && !in_array($item, $availableFields, true))
						{
							throw new UnknownDtoPropertyException($dto->getShortName(), $item);
						}

						if (str_starts_with($item, 'UF_'))
						{
							$structure->userFields[] = $item;

							continue;
						}

						$structure->items[] = $item;

						continue;
					}

					self::processRelationField($item, $structure, $request);
				}
				else
				{
					throw new InvalidSelectException($item);
				}
			}
		}

		return $structure;
	}

	public function getList(): array
	{
		return $this->items;
	}

	private static function processRelationField(string $field, self $structure, Request $request): void
	{
		$parts = explode('.', $field, 2);
		$relationName = $parts[0];
		$remaining = $parts[1] ?? null;

		/** @var Dto $dto */
		$parentDto = $structure::getDto($request->getDtoClass());

		$relation = $request->getRelation($relationName);

		if ($relation === null)
		{
			if (!isset($parentDto->getFields()[$relationName]) || $parentDto->getFields()[$relationName]->getRelation() === null)
			{
				throw new UnknownDtoPropertyException($parentDto->getShortName(), $relationName);
			}

			/** @var DtoField $relationDtoField */
			$relationDtoField = $parentDto->getFields()[$relationName];

			$type = $relationDtoField->getPropertyType();
			if ($type === DtoCollection::class)
			{
				$childDtoReflection = PropertyHelper::getReflection($parentDto->getFields()[$relationName]->getElementType());
			}
			else
			{
				$childDtoReflection = PropertyHelper::getReflection($type);
			}

			$childDto = self::getDto($childDtoReflection->getName());
			if (!$childDto)
			{
				$childDto = $childDtoReflection->getName()::create();
				self::addDto($childDto);
			}

			$relationRequest = new ListRequest($childDtoReflection->getName());
			$relationRequest->select = self::create([], $relationRequest->getDtoClass(), $relationRequest);
			if ($relationDtoField->getRelation()->sort !== null)
			{
				$relationRequest->order = OrderStructure::create(
					$relationDtoField->getRelation()->sort['order'],
					$relationRequest->getDtoClass(), $relationRequest,
				);
			}

			$relation = new Relation(
				$relationName,
				$childDto,
				$relationDtoField->getRelation()->thisField,
				$relationDtoField->getRelation()->refField,
				$relationRequest,
				$relationDtoField->getRelation()->multiple,
			);
			$relation->getRequest()->select->relationFields[] = $relationDtoField->getRelation()->refField;
			$request->addRelation($relation);
			$structure->relationFields[] = $relationDtoField->getRelation()->thisField;
		}

		if ($remaining !== null)
		{
			$childDto = self::getDto($relation->getRequest()->getDtoClass());

			$relation->getRequest()->select->items[] = $remaining;
			if (strpos($remaining, '.') !== false)
			{
				self::processRelationField($remaining, $relation->getRequest()->select, $relation->getRequest());
			}
			else
			{
				if (!isset($childDto->getFields()[$remaining]))
				{
					throw new UnknownDtoPropertyException($childDto->getShortName(), $remaining);
				}
			}
		}
	}

	public function getRelationFields(): array
	{
		return $this->relationFields;
	}
}
