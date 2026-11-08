<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Interaction\Relation;
use Bitrix\Rest\V3\Interaction\Request\Request;

abstract class ResponseWithRelations extends Response
{
	protected ?Request $parentRequest = null;

	/**
	 * @var Relation[]
	 */
	protected array $relations = [];

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

		return $result;
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
