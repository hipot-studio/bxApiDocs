<?php

namespace Bitrix\Rest\V3\Structure;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\PropertyHelper;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Interaction\Relation;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Schema\Scope;
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

	/** @var array<string, self> */
	private array $nestedStructures = [];

	/**
	 * Child requests for nested selects (ORM relations and embedded DTOs).
	 * Used to resolve nested dtoClass when processing dotted paths.
	 *
	 * @var array<string, Request>
	 */
	private array $nestedRequests = [];

	public static function create(mixed $value, string $dtoClass, ?Request $request = null): self
	{
		$structure = new self();

		$value = (array)$value;

		$dto = self::getDto($dtoClass);

		$fields = $dto->getFields();

		$availableFields = [];

		if ($request !== null && isset($request->getOptions()['scope']) && $request->getOptions()['scope'] instanceof Scope)
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

						// A bare nested-DTO name (no dot) means "expand all sub-fields".
						// ORM relations ([RelationToOne]/[RelationToMany]) go through
						// ResponseWithRelations; embedded DTOs (no FK attribute) stay
						// in nestedStructures for MappedBy / inline mapping.
						if (self::isOrmRelationField($fields[$item]))
						{
							self::processRelationField($item, $structure, $request);

							continue;
						}

						if (self::isNestedDtoField($fields[$item]))
						{
							self::processEmbeddedDtoField($item, $structure, $request);

							continue;
						}

						$structure->items[] = $item;

						continue;
					}

					$rootField = explode('.', $item, 2)[0];
					if (
						!empty($availableFields)
						&& !in_array($item, $availableFields, true)
						&& !in_array($rootField, $availableFields, true)
					)
					{
						throw new UnknownDtoPropertyException($dto->getShortName(), $rootField);
					}

					self::processNestedSelectPath($item, $structure, $request, $fields);
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
	 * True when the field is an ORM-style relation with #[RelationToOne] / #[RelationToMany].
	 * Only these participate in ResponseWithRelations sub-requests.
	 */
	private static function isOrmRelationField(DtoField $field): bool
	{
		return $field->getRelation() !== null;
	}

	/**
	 * Nested DTO / DtoCollection property — with or without FK relation metadata.
	 */
	private static function isNestedDtoField(DtoField $field): bool
	{
		$type = $field->getPropertyType();
		if (TypeHelper::isDto($type))
		{
			return true;
		}

		if ($type === DtoCollection::class)
		{
			$elementType = $field->getElementType();

			return TypeHelper::isDto($elementType);
		}

		return false;
	}

	/**
	 * @param \ArrayAccess<string, DtoField>|array<string, DtoField> $fields
	 */
	private static function processNestedSelectPath(
		string $field,
		self $structure,
		Request $request,
		\ArrayAccess|array $fields,
	): void
	{
		$root = explode('.', $field, 2)[0];
		if (!isset($fields[$root]))
		{
			$parentDto = self::getDto($request->getDtoClass());

			throw new UnknownDtoPropertyException($parentDto->getShortName(), $root);
		}

		if (self::isOrmRelationField($fields[$root]))
		{
			self::processRelationField($field, $structure, $request);

			return;
		}

		if (self::isNestedDtoField($fields[$root]))
		{
			self::processEmbeddedDtoField($field, $structure, $request);

			return;
		}

		$parentDto = self::getDto($request->getDtoClass());

		throw new UnknownDtoPropertyException($parentDto->getShortName(), $field);
	}

	/**
	 * Nested DTO without #[RelationToOne]/#[RelationToMany] — "entity in entity"
	 * populated by MappedBy (or manually), not via ORM relation dispatch.
	 */
	private static function processEmbeddedDtoField(string $field, self $structure, Request $request): void
	{
		$parts = explode('.', $field, 2);
		$embeddedName = $parts[0];
		$remaining = $parts[1] ?? null;

		$parentDto = self::getDto($request->getDtoClass());
		if (!isset($parentDto->getFields()[$embeddedName]))
		{
			throw new UnknownDtoPropertyException($parentDto->getShortName(), $embeddedName);
		}

		/** @var DtoField $embeddedDtoField */
		$embeddedDtoField = $parentDto->getFields()[$embeddedName];
		if (!self::isNestedDtoField($embeddedDtoField) || self::isOrmRelationField($embeddedDtoField))
		{
			throw new UnknownDtoPropertyException($parentDto->getShortName(), $field);
		}

		$childDtoClass = self::resolveNestedDtoClass($embeddedDtoField);
		$childDto = self::getDto($childDtoClass);
		if ($childDto === null)
		{
			$childDto = $childDtoClass::create();
			self::addDto($childDto);
		}

		$childRequest = $structure->ensureEmbeddedNested($embeddedName, $childDtoClass);
		$subSelect = $structure->nestedStructures[$embeddedName];

		if ($remaining === null)
		{
			foreach (self::getScalarFieldNames($childDtoClass) as $scalar)
			{
				if (!in_array($scalar, $subSelect->items, true))
				{
					$subSelect->items[] = $scalar;
				}
			}

			return;
		}

		$childFields = $childDto->getFields();
		$remainingParts = explode('.', $remaining, 2);
		$next = $remainingParts[0];

		if (!isset($childFields[$next]))
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $next);
		}

		if (self::isOrmRelationField($childFields[$next]))
		{
			// ORM relations under an embedded DTO are not part of the executable
			// request graph (childRequest is synthetic / not dispatched).
			throw new InvalidSelectException($field);
		}

		if (self::isNestedDtoField($childFields[$next]))
		{
			self::processEmbeddedDtoField($remaining, $subSelect, $childRequest);

			return;
		}

		if (isset($remainingParts[1]))
		{
			throw new UnknownDtoPropertyException($childDto->getShortName(), $remaining);
		}

		if (!in_array($next, $subSelect->items, true))
		{
			$subSelect->items[] = $next;
		}
	}

	private function ensureEmbeddedNested(string $name, string $childDtoClass): Request
	{
		if (!isset($this->nestedStructures[$name]))
		{
			$childRequest = new ListRequest($childDtoClass);
			$childRequest->select = new self();
			$this->nestedStructures[$name] = $childRequest->select;
			$this->nestedRequests[$name] = $childRequest;
		}

		return $this->nestedRequests[$name];
	}

	private static function resolveNestedDtoClass(DtoField $field): string
	{
		$type = $field->getPropertyType();
		if ($type === DtoCollection::class)
		{
			return $field->getElementType();
		}

		return $type;
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
			$relationMeta = $relationDtoField->getRelation();

			// Nested DTO without FK relation metadata — MappedBy / inline path.
			if ($relationMeta === null && self::isNestedDtoField($relationDtoField))
			{
				self::processEmbeddedDtoField($field, $structure, $request);

				return;
			}

			if ($relationMeta === null)
			{
				throw new UnknownDtoPropertyException($parentDto->getShortName(), $field);
			}

			$type = $relationDtoField->getPropertyType();
			$isMultipleDto = $type === DtoCollection::class &&
				TypeHelper::isDto($relationDtoField->getElementType());

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

			if ($relationMeta->sort !== null)
			{
				$relationRequest->order = OrderStructure::create(
					$relationMeta->sort['order'],
					$relationRequest->getDtoClass(), $relationRequest,
				);
			}

			$relation = new Relation(
				$relationName,
				$childDto,
				$relationMeta->thisField,
				$relationMeta->refField,
				$relationRequest,
				$relationMeta->multiple,
			);
			$request->addRelation($relation);
			$structure->relationFields[] = $relationMeta->thisField;
			$structure->nestedStructures[$relationName] = $relation->getRequest()->select;
			$structure->nestedRequests[$relationName] = $relation->getRequest();
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
	 * Returns the list of scalar (non-nested) property names of a DTO class.
	 * Used to materialise "expand all" when a bare nested-DTO name appears in select,
	 * and as the default select when the client omits select entirely.
	 */
	public static function getScalarFieldNames(string $dtoClass): array
	{
		$dto = self::getDto($dtoClass);
		if ($dto === null)
		{
			$dto = $dtoClass::create();
			self::addDto($dto);
		}

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
			if (!self::isNestedDtoField($field))
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
	 * Relation / embedded fields are string keys mapping to their nested structured list.
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
