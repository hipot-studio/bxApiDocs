<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\UnknownFilterOperatorException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterValidator;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\ReferencesDtoFields;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of the list of the activities of one parent: `[["ownerId", "=", 212], ...conditions]`.
 *
 * The parent is named by exactly one condition among the conditions of the client themselves, by equality to a
 * positive integer; its type is the one of the route. Named anywhere deeper - in a nested list, in a group, under
 * a negation - or by any other operator, the parent would stop being the bound of the list and become one of its
 * conditions, so every such shape is refused. The owner pair of the row and the binding of the global list are
 * not names of this filter at all and are refused wherever they appear.
 *
 * Every other condition is joined to the parent by AND and read by {@see ItemListFilterStructure}, with the
 * fields, the groups and the limits of the global list ({@see ActivityListFilterStructure}).
 *
 * The transport of a contextual route wraps the filter of the body into a list of its own
 * (`CRestApiServer::applyBodyOverridesToQueryParams()`), so the value read here is that list of one filter. The
 * wrapper is taken off once: what lies under it is the list of the client, and a list inside that one is nested.
 * A tool of MCP is the one caller without the wrapper ({@see self::unwrap()}).
 *
 * A grant restricted to some fields restricts the conditions and not the parent: the parent is the context of
 * the route, so it is no field the filter names.
 */
final class ContextActivityListFilterStructure extends Structure implements ReferencesDtoFields
{
	/** The property of the request the filter is read into: the address of a refusal. */
	private const REQUEST_PROPERTY = 'filter';
	private const OWNER_ID = 'ownerId';
	private const FOREIGN_NAMES = ['ownerTypeId', 'binding'];
	private const NAMING_KEYS = ['leftOperand', 'parameters'];

	private int $ownerId = 0;

	private ?ItemListFilterStructure $conditions = null;

	private function __construct()
	{
	}

	/**
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 * @throws RequiredFieldInRequestException
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 * @throws UnknownFilterOperatorException
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		[$ownerId, $conditions] = self::split($value);

		$structure = new self();
		$structure->ownerId = $ownerId;
		if ($conditions !== [])
		{
			$structure->conditions = ItemListFilterStructure::create($conditions, $dtoClass, $request);
		}

		return $structure;
	}

	/**
	 * The fields of the conditions, as the standard filter names them. The parent is not among them.
	 *
	 * @return list<string>
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 * @throws RequiredFieldInRequestException
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 * @throws UnknownFilterOperatorException
	 */
	public static function getReferencedFields(mixed $value): array
	{
		[, $conditions] = self::split($value);

		return $conditions === [] ? [] : FilterStructure::getReferencedFields($conditions);
	}

	/** Always positive: {@see create()} refuses a filter without a parent. */
	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	public function getConditions(): ?ItemListFilterStructure
	{
		return $this->conditions;
	}

	/**
	 * Splits the filter into the parent and the conditions beside it, refusing what the filter may not name.
	 *
	 * @return array{int, list<array>}
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 * @throws RequiredFieldInRequestException
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 * @throws UnknownFilterOperatorException
	 */
	private static function split(mixed $value): array
	{
		if (!is_array($value))
		{
			throw new RequiredFieldInRequestException(self::REQUEST_PROPERTY);
		}

		$ownerId = null;
		$conditions = [];
		foreach (self::nodesOf(self::unwrap($value)) as $node)
		{
			if (!is_array($node))
			{
				throw new InvalidFilterException($node);
			}
			self::rejectNames($node, self::FOREIGN_NAMES);

			if (array_is_list($node) && ($node[0] ?? null) === self::OWNER_ID)
			{
				if ($ownerId !== null)
				{
					throw new InvalidFilterException($node);
				}
				$ownerId = self::readOwnerId($node);

				continue;
			}

			self::rejectNames($node, [self::OWNER_ID]);
			$conditions[] = $node;
		}

		if ($ownerId === null)
		{
			throw new RequiredFieldsInRequestFilterPropertyException(self::REQUEST_PROPERTY, [self::OWNER_ID]);
		}

		return [$ownerId, $conditions];
	}

	/**
	 * The filter of the client under the list the transport put it into.
	 *
	 * A tool of MCP hands the filter over as it is (`RestMethodInvoker::mergeFixedQueryParamsWithArguments()`): the
	 * route fixes no condition, so nothing is put around it. The REST transport always answers a list of one
	 * element, so a longer list is that filter of a tool: its conditions, or its single condition.
	 *
	 * @throws InvalidFilterException
	 */
	private static function unwrap(array $value): array
	{
		if ($value === [])
		{
			return [];
		}
		if (!array_is_list($value))
		{
			throw new InvalidFilterException($value);
		}
		if (count($value) === 1 && is_array($value[0]))
		{
			return $value[0];
		}
		if (count($value) > 1 && (is_array($value[0]) || is_string($value[0])))
		{
			return $value;
		}

		throw new InvalidFilterException($value);
	}

	/**
	 * The conditions of the client themselves: the elements of its list, or the single condition it sent in place
	 * of a list.
	 *
	 * @throws InvalidFilterException
	 */
	private static function nodesOf(array $filter): array
	{
		if (!array_is_list($filter))
		{
			throw new InvalidFilterException($filter);
		}
		if ($filter === [] || (is_array($filter[0]) && !isset($filter[0]['expression'])))
		{
			return $filter;
		}

		return [$filter];
	}

	/**
	 * @throws InvalidFilterException
	 * @throws InvalidRequestFieldTypeException
	 * @throws UnknownFilterOperatorException
	 */
	private static function readOwnerId(array $condition): int
	{
		[$operator, $ownerId] = match (count($condition))
		{
			2 => [is_array($condition[1]) ? Operator::In : Operator::Equal, $condition[1]],
			3 => [
				FilterValidator::validateOperator(is_string($condition[1]) ? $condition[1] : null),
				$condition[2],
			],
			default => throw new InvalidFilterException($condition),
		};
		if ($operator !== Operator::Equal)
		{
			throw new InvalidFilterException($condition);
		}
		if (!is_int($ownerId))
		{
			throw new InvalidRequestFieldTypeException(self::OWNER_ID, 'int');
		}
		if ($ownerId <= 0)
		{
			throw new InvalidFilterException($condition);
		}

		return $ownerId;
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
