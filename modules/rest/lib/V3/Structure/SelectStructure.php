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

	private array $nestedStructures = [];

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

						// A bare relation name (no dot) means "expand all sub-fields".
						// Route through processRelationField so the relation is
						// registered with an empty sub-select.
						if (self::isRelationField($fields[$item]))
						{
							self::processRelationField($item, $structure, $request);

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

	/**
	 * Checks whether the given DTO field is a relation — i.e. its type is a
	 * Dto subclass (RelationToOne-style) or a DtoCollection of Dto subclasses
	 * (RelationToMany-style). Such fields cannot be selected as plain scalars;
	 * a bare name in `select` is treated as "expand all sub-fields".
	 */
	private static function isRelationField(DtoField $field): bool
	{
		$type = $field->getPropertyType();
		if (is_subclass_of($type, Dto::class))
		{
			return true;
		}

		if ($type === DtoCollection::class)
		{
			$elementType = $field->getElementType();

			return $elementType !== null && is_subclass_of($elementType, Dto::class);
		}

		return false;
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
			if (!isset($parentDto->getFields()[$relationName]))
			{
				throw new UnknownDtoPropertyException($parentDto->getShortName(), $relationName);
			}

			/** @var DtoField $relationDtoField */
			$relationDtoField = $parentDto->getFields()[$relationName];

			$type = $relationDtoField->getPropertyType();
			$isSingleDto = is_subclass_of($type, Dto::class);
			$isMultipleDto = $type === DtoCollection::class &&
				$relationDtoField->getElementType() !== null &&
				is_subclass_of($relationDtoField->getElementType(), Dto::class);

			if ($relationDtoField->getRelation() === null && !$isSingleDto && !$isMultipleDto)
			{
				throw new UnknownDtoPropertyException($parentDto->getShortName(), $field);
			}

			if ($isMultipleDto)
			{
				$childDtoReflection = PropertyHelper::getReflection($relationDtoField->getElementType());
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

			$fromField = $relationDtoField->getRelation()?->thisField ?? $remaining;
			$toField = $relationDtoField->getRelation()?->refField ?? $relationDtoField->getPropertyName();

			$relation = new Relation(
				$relationName,
				$childDto,
				$fromField,
				$toField,
				$relationRequest,
				$relationDtoField->getRelation()?->multiple ?? $isMultipleDto,
			);
			$request->addRelation($relation);
			$structure->relationFields[] = $fromField;
			$structure->nestedStructures[$relationName] = $relation->getRequest()->select;
		}

		$subSelect = $relation->getRequest()->select;

		if ($remaining === null)
		{
			// Bare relation name (e.g. select=["author"]) — caller wants every
			// scalar field of the related DTO. Materialise the full scalar list
			// into items so the intent survives the sub-request hop (the HTTP
			// body carries items verbatim, and the sub-controller has no idea
			// about parent-side flags). This also makes the merge step in
			// ResponseWithRelations keep the toField naturally — it's part of
			// the explicit list.
			//
			// Merge — DO NOT overwrite. The bare branch must be order-independent
			// with respect to narrow `relation.subfield` paths: a narrow entry
			// processed earlier has already populated $subSelect->items with
			// `subfield` (or deeper nested paths) and $subSelect->relationFields
			// with child-side FKs needed to dispatch its own nested relations.
			// Reassigning would wipe both, dropping nested relation requests
			// silently (e.g. select=["chat.author.email", "chat"] would lose
			// `author.email` from the chat sub-request, while the reverse order
			// preserves it — an inconsistency invisible at the API surface but
			// fatal to round-trip correctness).
			foreach (self::getScalarFieldNames($relation->getRequest()->getDtoClass()) as $scalar)
			{
				if (!in_array($scalar, $subSelect->items, true))
				{
					$subSelect->items[] = $scalar;
				}
			}

			return;
		}

		// Ensure the sub-request returns the toField so the merge step in
		// ResponseWithRelations can match child rows back to parent FKs.
		$childToField = $relation->getToField();
		if (!in_array($childToField, $subSelect->relationFields, true))
		{
			$subSelect->relationFields[] = $childToField;
		}

		$childDto = self::getDto($relation->getRequest()->getDtoClass());

		// Deduplicate — when a bare `relation` was processed before a narrower
		// `relation.subfield`, the subfield is already covered by the explicit
		// scalar list, so we skip adding it again.
		if (!in_array($remaining, $subSelect->items, true))
		{
			$subSelect->items[] = $remaining;
		}

		if (strpos($remaining, '.') !== false)
		{
			self::processRelationField($remaining, $subSelect, $relation->getRequest());
		}
		else
		{
			if (!isset($childDto->getFields()[$remaining]))
			{
				throw new UnknownDtoPropertyException($childDto->getShortName(), $remaining);
			}
		}
	}

	/**
	 * Returns the list of scalar (non-relation) property names of a DTO class.
	 * Used to materialise "expand all" when a bare relation name appears in select.
	 */
	private static function getScalarFieldNames(string $dtoClass): array
	{
		$dto = self::getDto($dtoClass);
		$names = [];
		foreach ($dto->getFields() as $field)
		{
			// Only class-declared scalar properties — user fields (UF_*) and
			// other dynamic fields are not part of "expand all". A caller can
			// still ask for them explicitly by name (e.g. select=["UF_PHONE"]).
			if ($field->getType() !== DtoField::DTO_FIELD_TYPE_PROPERTY)
			{
				continue;
			}
			if (!self::isRelationField($field))
			{
				$names[] = $field->getPropertyName();
			}
		}

		return $names;
	}

	public function getRelationFields(): array
	{
		return $this->relationFields;
	}

	/**
	 * Returns the full structured list of requested fields.
	 *
	 * Top-level scalar fields are string values at integer keys.
	 * Relation fields are string keys mapping to their nested structured list.
	 *
	 * Example: select=['id', 'name', 'category.id', 'tags.id']
	 *          → ['id', 'name', 'category' => ['id'], 'tags' => ['id']]
	 *
	 * Supports arbitrary depth:
	 *          select=['id', 'category.subcategory.title']
	 *          → ['id', 'category' => ['subcategory' => ['title']]]
	 */
	public function getStructuredList(): array
	{
		$result = $this->items;

		foreach ($this->nestedStructures as $relationName => $childSelect)
		{
			$result[$relationName] = $childSelect->getStructuredList();
		}

		return $result;
	}
}
