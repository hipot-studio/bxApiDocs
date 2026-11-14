<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\ReferencesDtoFields;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of the global list of activities: the standard conditions, plus an optional binding
 * `{"binding": {"entityTypeId": 2, "entityId": 100}, "conditions": [...]}` joined to them by AND.
 *
 * An activity has several bindings, so the binding is a filter of its own rather than a field: it is named
 * once, at the top level only, without an operator, and never inside a group, where OR or NOT would turn
 * it into a question the bindings cannot answer. The owner pair of the row is not published, so its names
 * are refused wherever they appear instead of being mistaken for the binding.
 *
 * The conditions are read by {@see ItemListFilterStructure}, which keeps the limits of a CRM filter:
 * depth, number of nodes and values of `in`.
 *
 * A grant restricted to some fields restricts the filter too: the binding names the field of the bindings.
 */
final class ActivityListFilterStructure extends Structure implements ReferencesDtoFields
{
	private const BINDING = 'binding';
	private const BINDING_DTO_FIELD = 'bindings';
	private const CONDITIONS = 'conditions';
	private const BINDING_FIELDS = ['entityTypeId', 'entityId'];
	private const OWNER_FIELDS = ['ownerId', 'ownerTypeId'];
	private const NAMING_KEYS = ['leftOperand', 'parameters'];

	private ?int $bindingEntityTypeId = null;

	private ?int $bindingEntityId = null;

	private ?ItemListFilterStructure $conditions = null;

	private function __construct()
	{
	}

	/**
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		[$binding, $conditions] = self::split($value);

		$structure = new self();
		if ($binding !== null)
		{
			[$structure->bindingEntityTypeId, $structure->bindingEntityId] = $binding;
		}
		if ($conditions !== [])
		{
			$structure->conditions = ItemListFilterStructure::create($conditions, $dtoClass, $request);
		}

		return $structure;
	}

	/**
	 * The fields of the conditions, as the standard filter names them, and the bindings of an activity, which the
	 * binding is looked for among.
	 *
	 * @return list<string>
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 */
	public static function getReferencedFields(mixed $value): array
	{
		[$binding, $conditions] = self::split($value);

		$fields = $binding === null ? [] : [self::BINDING_DTO_FIELD];
		if ($conditions !== [])
		{
			$fields = [...$fields, ...FilterStructure::getReferencedFields($conditions)];
		}

		return array_values(array_unique($fields));
	}

	public function hasBinding(): bool
	{
		return $this->bindingEntityTypeId !== null;
	}

	public function getBindingEntityTypeId(): ?int
	{
		return $this->bindingEntityTypeId;
	}

	public function getBindingEntityId(): ?int
	{
		return $this->bindingEntityId;
	}

	public function getConditions(): ?ItemListFilterStructure
	{
		return $this->conditions;
	}

	/**
	 * Splits the filter into its binding and its conditions, refusing what the filter may not name.
	 *
	 * @return array{array{int, int}|null, array}
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function split(mixed $value): array
	{
		$value = (array)$value;
		self::rejectNames($value, self::OWNER_FIELDS);

		$binding = null;
		$conditions = $value;
		if (array_key_exists(self::BINDING, $value))
		{
			if (array_diff(array_keys($value), [self::BINDING, self::CONDITIONS]) !== [])
			{
				throw new InvalidFilterException($value);
			}

			$binding = self::readBinding($value[self::BINDING]);

			$conditions = $value[self::CONDITIONS] ?? [];
			if (!is_array($conditions) || !array_is_list($conditions))
			{
				throw new InvalidFilterException([self::CONDITIONS => $conditions]);
			}
		}

		self::rejectNames($conditions, [self::BINDING]);

		return [$binding, $conditions];
	}

	/**
	 * @return array{int, int}
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function readBinding(mixed $binding): array
	{
		if (!is_array($binding) || array_is_list($binding))
		{
			throw new InvalidFilterException([self::BINDING => $binding]);
		}

		$keys = array_keys($binding);
		if (count($keys) !== count(self::BINDING_FIELDS) || array_diff(self::BINDING_FIELDS, $keys) !== [])
		{
			throw new InvalidFilterException([self::BINDING => $binding]);
		}

		foreach (self::BINDING_FIELDS as $field)
		{
			if (!is_int($binding[$field]) || $binding[$field] <= 0)
			{
				throw new InvalidRequestFieldTypeException(self::BINDING . '.' . $field, 'positive int');
			}
		}

		return [$binding['entityTypeId'], $binding['entityId']];
	}

	/**
	 * Refuses the names wherever a filter can name a field: a key of a group, the first operand of a
	 * condition, the left operand of a static condition and the parameter of an expression.
	 *
	 * @param string[] $names
	 * @throws InvalidFilterException
	 */
	private static function rejectNames(array $node, array $names): void
	{
		$isCondition = array_is_list($node) && is_string($node[0] ?? null);
		if ($isCondition && in_array($node[0], $names, true))
		{
			throw new InvalidFilterException($node[0]);
		}

		foreach ($node as $key => $item)
		{
			if ($isCondition && !isset($item['expression']))
			{
				continue;
			}
			if (is_string($key) && in_array($key, $names, true))
			{
				throw new InvalidFilterException($key);
			}
			if (is_string($key) && in_array($key, self::NAMING_KEYS, true) && in_array($item, $names, true))
			{
				throw new InvalidFilterException($item);
			}
			if (is_array($item))
			{
				self::rejectNames($item, $names);
			}
		}
	}
}
