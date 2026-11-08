<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\ListRequest}: one owner, and
 * optionally one type of address.
 *
 * The set of conditions is closed, and closing it is the point. Addresses are read within a single owner
 * and for a single type of address at most
 * ({@see \Bitrix\Crm\V2\Public\Provider\Address\OwnerAddressProvider::getList()}), so a condition the read
 * cannot express is refused rather than dropped: a set of owners, a range of types and an `or` of
 * conditions all have no reading behind them, and answering one of them with a narrower set would answer a
 * different question than the one asked - quietly, and for good, once the group is published.
 *
 * The owner is required and the type is not, which is the whole of the difference between the two
 * conditions this structure understands.
 *
 * ### Why the requirement of the owner is written here
 *
 * `#[FilterRequired]` cannot state it, for the two reasons
 * {@see ProductRowListFilterStructure} writes out and one more of this group. The attribute takes its
 * fields as a constant expression while the owner of an address is named after the entity of the route -
 * `contactId` on one, `companyId` on the other - so a single class cannot name it; and
 * {@see Request::create()} honours the attribute through `FilterStructure::getSimpleFilterConditions()`,
 * which sees through exactly one level of nesting, while every route of the group carries a trusted entity
 * type and therefore travels through `CRestApiServer::applyBodyOverridesToQueryParams()`, which wraps the
 * filter of the body into a list of its own. The requirement is checked here instead, and answered with the
 * exception the attribute itself raises, so a client sees no difference.
 *
 * The extra level of the transport needs no undoing: a node whose first element is an array is read as a
 * list of nodes, and the wrapper is one of those.
 */
final class OwnerAddressListFilterStructure extends Structure
{
	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\ListRequest::$filter */
	private const REQUEST_PROPERTY = 'filter';

	/**
	 * The type of an address is published under this name on every route of the group, while the owner is
	 * not - which is what tells the two apart in {@see self::ownerFieldNameOf()}.
	 */
	private const TYPE_FIELD = 'typeId';

	/** The transport adds one level, a client list of conditions another, a condition is the third. */
	private const MAX_DEPTH = 4;

	private string $ownerFieldName = '';

	private ?int $ownerId = null;

	private ?int $typeId = null;

	private function __construct()
	{
	}

	/**
	 * @throws InvalidFilterException When the filter holds a condition the read of the domain cannot
	 *         express: another field, another operator, a repeated field or a grouping of conditions.
	 * @throws RequiredFieldsInRequestFilterPropertyException When the filter names no owner.
	 * @throws SystemException When the contract of the route does not name the owner of an address.
	 */
	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value))
		{
			throw new InvalidFilterException($value);
		}

		if (self::getDto($dtoClass) === null)
		{
			self::addDto($dtoClass::create());
		}

		$structure = new self();
		$structure->ownerFieldName = self::ownerFieldNameOf($dtoClass);
		$structure->readNode($value, $dtoClass, $request, 1);

		if ($structure->ownerId === null)
		{
			throw new RequiredFieldsInRequestFilterPropertyException(
				self::REQUEST_PROPERTY,
				[$structure->ownerFieldName],
			);
		}

		return $structure;
	}

	/** Always positive: {@see self::create()} refuses a filter without an owner. */
	public function getOwnerId(): int
	{
		return $this->ownerId ?? 0;
	}

	/** The type of address to read, `null` for every type of the owner. */
	public function getTypeId(): ?int
	{
		return $this->typeId;
	}

	/**
	 * The name the owner is published under on this route - `contactId` or `companyId` - read out of the
	 * contract of the route rather than written down: the name is a decision of the route, it already stands
	 * in the configuration of the route and in the DTO that configuration generates, and a third copy here
	 * would be one more place to forget.
	 *
	 * The owner is the field of the contract an address cannot be created without, apart from the type of
	 * the address, whose name is the same on every route
	 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator}). A contract that
	 * names no such field, or more than one, is a broken route rather than a bad request.
	 *
	 * @throws SystemException
	 */
	private static function ownerFieldNameOf(string $dtoClass): string
	{
		/** @var Dto $dto */
		$dto = $dtoClass::create();

		$names = [];
		foreach ($dto->getFields() as $field)
		{
			$namesOwner =
				$field->isFilterable()
				&& $field->getPropertyName() !== self::TYPE_FIELD
				&& in_array(RequiredGroup::Add->value, $field->getRequiredGroups() ?? [], true)
			;
			if ($namesOwner)
			{
				$names[] = $field->getPropertyName();
			}
		}

		if (count($names) !== 1)
		{
			throw new SystemException(
				"The address contract {$dto->getShortName()} does not name the owner of an address.",
			);
		}

		return $names[0];
	}

	private function readNode(array $node, string $dtoClass, Request $request, int $depth): void
	{
		if ($node === [])
		{
			return;
		}

		if (!array_is_list($node))
		{
			throw new InvalidFilterException($node);
		}

		if (!is_array($node[0]))
		{
			$this->readCondition($node, $dtoClass, $request);

			return;
		}

		if ($depth >= self::MAX_DEPTH)
		{
			throw new InvalidFilterException($node);
		}

		foreach ($node as $child)
		{
			if (!is_array($child))
			{
				throw new InvalidFilterException($child);
			}

			$this->readNode($child, $dtoClass, $request, $depth + 1);
		}
	}

	private function readCondition(array $condition, string $dtoClass, Request $request): void
	{
		$normalized = self::normalizeCondition($condition, $dtoClass, $request);
		$fieldName = $normalized->getLeftOperand();

		if ($fieldName === $this->ownerFieldName)
		{
			self::denyRepeatedField($condition, $this->ownerId !== null);
			$this->ownerId = self::readIdentifier($condition, $normalized);

			return;
		}

		if ($fieldName === self::TYPE_FIELD)
		{
			self::denyRepeatedField($condition, $this->typeId !== null);
			$this->typeId = self::readIdentifier($condition, $normalized);

			return;
		}

		// Reachable only if the contract gains a filterable field this structure does not read: an
		// unknown field and a field without #[Filterable] are refused by the framework before this point.
		throw new InvalidFilterException($condition);
	}

	/** One field, one condition: two conditions on one field are not an `and` this read can express. */
	private static function denyRepeatedField(array $condition, bool $isRepeated): void
	{
		if ($isRepeated)
		{
			throw new InvalidFilterException($condition);
		}
	}

	/**
	 * The framework does the checks that belong to the DTO: an unknown property, a property without
	 * `#[Filterable]`, an unknown operator and a value of the wrong type are all refused there.
	 */
	private static function normalizeCondition(array $condition, string $dtoClass, Request $request): Condition
	{
		$normalized = FilterStructure::create($condition, $dtoClass, $request)->getConditions()[0] ?? null;
		if (!$normalized instanceof Condition)
		{
			throw new InvalidFilterException($condition);
		}

		return $normalized;
	}

	/**
	 * The identifier a condition fixes a field to. Both fields of this filter are read the same way: the
	 * domain takes one owner and one type of address, so a set of either - and a range of either - is a
	 * question this read cannot answer.
	 */
	private static function readIdentifier(array $condition, Condition $normalized): int
	{
		$value = $normalized->getRightOperand();
		if ($normalized->getOperator() !== Operator::Equal || !is_int($value) || $value <= 0)
		{
			throw new InvalidFilterException($condition);
		}

		return $value;
	}
}
