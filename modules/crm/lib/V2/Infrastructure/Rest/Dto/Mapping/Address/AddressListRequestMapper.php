<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\ListAddressRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields;
use Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Structure\Filtering;

/**
 * Translates the parts of a list request into the read parameters of
 * {@see AddressRepository::findAll()}.
 *
 * Nothing is renamed on the way: the read speaks the field names of an address, the very names
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\PortalAddressDto} publishes, so a condition keeps
 * the name the client wrote and only the shape of the tree changes. Turning a name into a column of the
 * storage belongs to the read alone. What the standard filter of REST v3 can express and an address cannot
 * - an expression as an operand - is refused here rather than passed on: the read answers for a closed list
 * of columns, and an expression reaching it would be a failure of the portal instead of an answer to the
 * client.
 *
 * The tree built here stays the caller's: the read renames the columns of a copy of it and leaves this one
 * in field names, so the same tree read twice answers the same rows.
 *
 * Two things this mapper deliberately does **not** do:
 *
 * - it does not append a tie-breaker to the ordering. The identifier settles whatever an ordering leaves
 *   equal inside the read itself, an empty ordering included, and a second place to keep in step would only
 *   be a place to fall out of step;
 * - it does not decide what an address looks like in the answer. {@see mapSelect()} says which fields were
 *   asked for, and the same list serves twice: the read loads those fields and
 *   {@see PortalAddressDtoMapper} answers them.
 */
final class AddressListRequestMapper
{
	private const FIELD_ID = 'id';

	private const FIELD_OWNER_TYPE_ID = 'ownerTypeId';

	/** Where an owner type is named, in the notation of the answer. */
	private const OWNER_TYPE_ID_FILTER_FIELD = 'filter.' . self::FIELD_OWNER_TYPE_ID;

	/**
	 * The operators that ask for the owner types they name, rather than exclude or bound them, as long as
	 * no negation turns them around.
	 */
	private const OWNER_TYPE_REQUESTING_OPERATORS = [
		Filtering\Operator::Equal,
		Filtering\Operator::In,
	];

	/**
	 * @throws InvalidFilterException an operand an address cannot be filtered by.
	 * @throws RequestFilterValidationException an owner of a type the method does not serve.
	 */
	public function mapFilter(?Filtering\FilterStructure $filter): ?Filter\ConditionTree
	{
		return $filter === null ? null : self::mapGroup($filter, false);
	}

	/**
	 * @return array<string, string> address field name => `ASC`/`DESC`; empty when the client asked for no
	 *         order of its own.
	 */
	public function mapOrder(?ItemListOrderStructure $order): array
	{
		return $order?->getList() ?? [];
	}

	/**
	 * The fields of an address the request is about, by the rule the whole REST of the CRM reads by, plus
	 * the identifier - always.
	 *
	 * The identifier is what a client addresses a row by: a page answered without one names nothing it
	 * could ask about again, and walking a whole portal by `id` would have nothing to walk with. So it is
	 * answered whether it was asked for or not. The rule of the CRM behind
	 * {@see RequestSelectedFields::resolve()} only makes it the *fallback* of a request that asked for
	 * nothing at all, and a request that named a field of its own would otherwise lose it.
	 *
	 * A request without a field set is therefore still answered with the identifier alone, and one that
	 * named fields is answered with those and the identifier beside them.
	 *
	 * The list serves twice over: the read loads these fields and
	 * {@see PortalAddressDtoMapper::getDtoCollectionByRows()} answers them. The read forces the identifier
	 * into its columns as well and for a reason of its own - a row it could not order by would have no
	 * settled page - so the two agree here rather than one leaning on the other.
	 *
	 * @return string[] address field names.
	 */
	public function mapSelect(ListAddressRequest $request): array
	{
		$fields = RequestSelectedFields::resolve($request, self::FIELD_ID);

		return in_array(self::FIELD_ID, $fields, true) ? $fields : [...$fields, self::FIELD_ID];
	}

	/**
	 * The bounds of the page the framework leaves unchecked, and the default page of the standard
	 * pagination when the client asked for none - the read is never left unbounded.
	 *
	 * @throws InvalidPaginationException
	 */
	public function mapPager(?ItemListPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}

	/**
	 * @param bool $isNegated whether the group of the caller sits under an odd number of negations. Carried
	 *        down because a condition under a negation asks for the opposite of what it names, which is what
	 *        {@see requireServedOwnerTypes()} decides by.
	 * @throws InvalidFilterException
	 * @throws RequestFilterValidationException
	 */
	private static function mapGroup(Filtering\FilterStructure $filter, bool $isNegated): Filter\ConditionTree
	{
		$tree = (new Filter\ConditionTree())
			->logic($filter->logic()->value)
			->negative($filter->isNegative())
		;
		$isNegated = $isNegated !== $filter->isNegative();

		foreach ($filter->getConditions() as $condition)
		{
			$tree->addCondition(match (true)
			{
				$condition instanceof Filtering\FilterStructure => self::mapGroup($condition, $isNegated),
				$condition instanceof Filtering\Condition => self::mapCondition($condition, $isNegated),
				default => throw new InvalidFilterException(get_debug_type($condition)),
			});
		}

		return $tree;
	}

	/**
	 * @throws InvalidFilterException
	 * @throws RequestFilterValidationException
	 */
	private static function mapCondition(Filtering\Condition $condition, bool $isNegated): Filter\Condition
	{
		$column = $condition->getLeftOperand();
		$operator = $condition->getOperator();
		$value = $condition->getRightOperand();
		if (!is_string($column) || $value instanceof Filtering\Expressions\Expression)
		{
			throw new InvalidFilterException([$column, $operator->value, $value]);
		}

		// An empty set matches nothing, and the ORM would write `IN ()` for it. Answered as a condition no
		// address meets - the identifiers of the rows are positive.
		if ($operator === Filtering\Operator::In && $value === [])
		{
			return new Filter\Condition(self::FIELD_ID, Filtering\Operator::Equal->value, 0);
		}

		if ($column === self::FIELD_OWNER_TYPE_ID)
		{
			self::requireServedOwnerTypes($column, $operator, $value, $isNegated);
		}

		return new Filter\Condition($column, $operator->value, $value);
	}

	/**
	 * An address of the portal belongs to a lead, a contact or a company, and a filter that **asks for** any
	 * other owner asks for something the method does not serve: it is refused with the address the type came
	 * in rather than answered with an empty page, which is the one answer a client cannot tell from "there is
	 * nothing there".
	 *
	 * Only a condition that names the types it wants is judged, and a negation decides which one does that:
	 * an equality or an `in` that no negation turns around, and a `!=` that an odd number of them turns into
	 * an equality. A condition that excludes a type (a `!=` of its own, an equality or an `in` under a
	 * negation) asks for nothing unserved: the read `and`-s its own limit of owners onto whatever the client
	 * wrote, so such a filter answers with served owners either way and a refusal of it would be false.
	 *
	 * A condition that bounds a range of types (`between`, `>`, `<`) is not judged either, and for a weaker
	 * reason: `between [2, 2]` does ask for exactly an unserved one and gets back the empty page a refusal is
	 * meant to replace. Refusing it would take a message that names a range where this one names a single
	 * type.
	 *
	 * The served types are read from {@see AddressRepository::ANCHOR_OWNER_TYPES} rather than restated here:
	 * a copy would part with the read the first time the read is widened.
	 *
	 * @param string $column the field the condition names; a refusal of the operand quotes it, as the
	 *        refusal of a condition of any other field does.
	 * @param mixed $value the operand as the condition carries it: one type or a list of them.
	 * @param bool $isNegated whether a negation of the filter turns this condition around.
	 * @throws InvalidFilterException an owner type that is not a number at all.
	 * @throws RequestFilterValidationException
	 */
	private static function requireServedOwnerTypes(
		string $column,
		Filtering\Operator $operator,
		mixed $value,
		bool $isNegated,
	): void
	{
		// Under an odd negation `!=` reads as an equality; nothing else turns into a request, as the
		// filter has no `not in` of its own - a negated group carries that meaning instead.
		$isRequestingOwnerTypes = $isNegated
			? $operator === Filtering\Operator::NotEqual
			: in_array($operator, self::OWNER_TYPE_REQUESTING_OPERATORS, true)
		;
		if (!$isRequestingOwnerTypes)
		{
			return;
		}

		foreach (is_array($value) ? $value : [$value] as $ownerTypeId)
		{
			if (!is_int($ownerTypeId))
			{
				throw new InvalidFilterException([$column, $operator->value, $value]);
			}

			if (!in_array($ownerTypeId, AddressRepository::ANCHOR_OWNER_TYPES, true))
			{
				throw new RequestFilterValidationException([
					new Error(
						new LocalizableMessage(
							'CRM_V2_REST_ADDRESS_OWNER_TYPE_IS_NOT_SERVED',
							['#OWNER_TYPE_ID#' => (string)$ownerTypeId],
						),
						self::OWNER_TYPE_ID_FILTER_FIELD,
					),
				]);
			}
		}
	}
}
