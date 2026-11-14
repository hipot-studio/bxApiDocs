<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Relation;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Schema\Scope;
use Bitrix\Rest\V3\Structure\SelectStructure;

abstract class ResponseWithRelations extends Response
{
	protected ?Request $parentRequest = null;

	/**
	 * @var Relation[]
	 */
	protected array $relations = [];

	private ?Scope $grantScope = null;

	private ?string $grantResourceDtoClass = null;

	public function toArray(): array
	{
		$result = parent::toArray();
		foreach ($this->getRelations() as $relation)
		{
			$relationResponse = $relation->getResponse();

			if ($relationResponse === null)
			{
				continue;
			}

			$relationData = $relationResponse->toArray();

			$relationName = $relation->getName();
			$fromField = $relation->getFromField();
			$toField = $relation->getToField();

			$isToFieldRequested = in_array($toField, $relation->getRequest()->select->getList(), true);

			$relationIndex = $this->indexRelationItems($relationData, $toField, $isToFieldRequested);

			if ($this instanceof ListResponse)
			{
				foreach ($result['items'] as &$item)
				{
					if (isset($item[$fromField]))
					{
						$item[$relationName] = $this->getRelationValue(
							$relationIndex,
							$item[$fromField],
							$relation->isMultiply(),
						);
					}
				}
				unset($item);
			}
			elseif ($this instanceof GetResponse)
			{
				if (isset($result['item'][$fromField]))
				{
					$result['item'][$relationName] = $this->getRelationValue(
						$relationIndex,
						$result['item'][$fromField],
						$relation->isMultiply(),
					);
				}
			}
		}

		return $this->applyGrantFieldGuard($result);
	}

	public function getParentRequest(): ?Request
	{
		return $this->parentRequest;
	}

	public function setParentRequest(Request $parentRequest): self
	{
		$this->parentRequest = $parentRequest;

		return $this;
	}

	/**
	 * Scope of the running method and DTO class of its resource: the field guard of the response
	 * relies on them when the action has no request argument or its request has no select.
	 */
	public function setGrantContext(Scope $scope, ?string $resourceDtoClass): self
	{
		$this->grantScope = $scope;
		$this->grantResourceDtoClass = $resourceDtoClass;

		return $this;
	}

	/**
	 * @return Relation[]
	 */
	public function getRelations(): array
	{
		return $this->relations;
	}

	public function setRelations(array $relations): self
	{
		$this->relations = $relations;

		return $this;
	}

	/**
	 * Under a grant restricted to fields, the resource data of the response keeps only the allowed
	 * top-level keys and the id, whatever the controller has filled in. The resource data are `item`,
	 * the elements of `items` and public properties holding DTOs of the resource class.
	 */
	private function applyGrantFieldGuard(array $result): array
	{
		$scope = $this->grantScope ?? $this->parentRequest?->getOptions()['scope'] ?? null;
		if (!$scope instanceof Scope || empty($scope->fields))
		{
			return $result;
		}

		$allowedKeys = array_flip($this->getGrantAllowedKeys($scope));
		$allowedKeys['id'] = true;

		if (isset($result['items']) && is_array($result['items']))
		{
			$result['items'] = $this->guardItems($result['items'], $allowedKeys);
		}

		if (isset($result['item']) && is_array($result['item']))
		{
			$result['item'] = array_intersect_key($result['item'], $allowedKeys);
		}

		$resourceDtoClass = $this->grantResourceDtoClass ?? $this->parentRequest?->getDtoClass();
		if ($resourceDtoClass === null)
		{
			return $result;
		}

		$properties = (new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC);
		foreach ($properties as $property)
		{
			$name = $property->getName();
			if (
				$name === 'item'
				|| $name === 'items'
				|| $property->isStatic()
				|| !$property->isInitialized($this)
				|| !isset($result[$name])
				|| !is_array($result[$name])
			)
			{
				continue;
			}

			$value = $property->getValue($this);
			if ($value instanceof Dto && $value instanceof $resourceDtoClass)
			{
				$result[$name] = array_intersect_key($result[$name], $allowedKeys);
			}
			elseif ($value instanceof DtoCollection && is_a($value->getType(), $resourceDtoClass, true))
			{
				$result[$name] = $this->guardItems($result[$name], $allowedKeys);
			}
		}

		return $result;
	}

	/**
	 * Top-level response keys allowed by the select, or by the grant fields when there is no select.
	 *
	 * @return string[]
	 */
	private function getGrantAllowedKeys(Scope $scope): array
	{
		$select = $this->parentRequest?->select ?? null;
		if ($select instanceof SelectStructure)
		{
			return $select->getResponseKeys();
		}

		$keys = [];
		foreach ($scope->fields as $field)
		{
			$keys[] = explode('.', (string)$field, 2)[0];
		}

		return array_values(array_unique($keys));
	}

	/**
	 * Elements that are not arrays cannot be checked against the allowed keys, so they are dropped.
	 */
	private function guardItems(array $items, array $allowedKeys): array
	{
		$guardedItems = [];
		foreach ($items as $item)
		{
			if (is_array($item))
			{
				$guardedItems[] = array_intersect_key($item, $allowedKeys);
			}
		}

		return $guardedItems;
	}

	/**
	 * Groups related records by the relation field value, keeping the order of the relation response.
	 *
	 * @return array{byKey: array<string, array[]>, nonKeyable: array<array{mixed, array}>}
	 */
	private function indexRelationItems(array $relationData, string $toField, bool $isToFieldRequested): array
	{
		if (isset($relationData['items']))
		{
			$relationItems = $relationData['items'];
		}
		elseif (isset($relationData['item']))
		{
			$relationItems = [$relationData['item']];
		}
		else
		{
			$relationItems = [];
		}

		$index = [
			'byKey' => [],
			'nonKeyable' => [],
		];

		foreach ($relationItems as $relationItem)
		{
			if (!isset($relationItem[$toField]))
			{
				continue;
			}

			$value = $relationItem[$toField];
			if (!$isToFieldRequested)
			{
				unset($relationItem[$toField]);
			}

			$key = $this->getRelationKey($value);
			if ($key === null)
			{
				$index['nonKeyable'][] = [$value, $relationItem];
			}
			else
			{
				$index['byKey'][$key][] = $relationItem;
			}
		}

		return $index;
	}

	private function getRelationValue(array $relationIndex, mixed $parentValue, bool $isMultiply): ?array
	{
		$key = $this->getRelationKey($parentValue);
		if ($key !== null)
		{
			$matchedItems = $relationIndex['byKey'][$key] ?? [];
		}
		else
		{
			$matchedItems = [];
			foreach ($relationIndex['nonKeyable'] as [$value, $relationItem])
			{
				if ($value === $parentValue)
				{
					$matchedItems[] = $relationItem;
				}
			}
		}

		return $isMultiply ? $matchedItems : ($matchedItems[0] ?? null);
	}

	/**
	 * Returns null for a value that cannot become an array key without breaking strict comparison.
	 */
	private function getRelationKey(mixed $value): ?string
	{
		if (is_int($value) || is_string($value))
		{
			// the type prefix keeps "1" and 1 apart: array key casting would merge them
			return get_debug_type($value) . ':' . $value;
		}

		return null;
	}
}
