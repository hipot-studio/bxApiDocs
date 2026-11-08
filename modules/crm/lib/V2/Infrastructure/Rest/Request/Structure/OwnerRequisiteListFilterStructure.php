<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractOwnerRequisiteDto;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Logic;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Structure;

/**
 * The filter of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\ListOwnerRequisiteRequest}:
 * the owner whose requisites are read, which is the whole of what the filter of this group carries.
 *
 * A requisite is read within its owner and nowhere else
 * ({@see \Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider::getListByOwner()}), and the owner of
 * a page is the one thing a client cannot leave out: a list of the requisites of the whole portal is
 * another method with another right behind it. So the filter has to name the owner, name it once, and
 * name it plainly - `["contactId", "=", 14]` on a route of the contact, `companyId` on one of the company
 * ({@see AbstractOwnerRequisiteDto::ownerFieldName()}, which is where either name comes from; this class
 * keeps no second list of the two).
 *
 * The owner is also the only thing the filter may carry. The contract of the group declares one filterable
 * field and it is the owner ({@see AbstractOwnerRequisiteDto}), so a condition over anything else is
 * refused by the framework before this class is reached, and what is left once the owner is taken out of
 * the filter is nothing at all. That is why no rest of the filter is handed on: a page of this group is
 * narrowed by naming its owner and in no other way.
 *
 * ### Why the requirement of the owner is written here
 *
 * {@see FilterRequired} cannot state it. The attribute is honoured by {@see Request::create()} only for a
 * property holding a {@see FilterStructure}, which is `final`, and it reads the filter through
 * `getSimpleFilterConditions()`, which sees through exactly one level of nesting. Every route of this
 * group carries a trusted `entityTypeId`, so the body travels through
 * `CRestApiServer::applyBodyOverridesToQueryParams()`, which wraps the filter of the body into a list of
 * its own. Measured on that path, the attribute refuses **every** filter the group publishes, a bare
 * `[["contactId", "=", 14]]` included: one level of the transport plus one level of the parser is already
 * one more than it can see through. The requirement is therefore checked here, and answered with the
 * exception the attribute raises itself - same class, same code, same message - so that nothing in the
 * answer says the wall was built by hand.
 *
 * The extra level of the transport needs no undoing: a node whose first element is an array is parsed as
 * a list of nodes, and the wrapper is one of those.
 *
 * ### Naming the owner some other way is not naming it
 *
 * A set of owners (`in`), a range of them (`between`, `>`, `<`), a negation of one, and one named inside
 * an `or` are all refused, and none of them is a question the read could be asked: a page belongs to one
 * owner, and the right to read it is the right to read that owner. They are told apart from a filter that
 * left the owner out altogether, though - a client that wrote the field is told what is wrong with the
 * condition it wrote rather than that the field is missing. Both refusals are refusals of the filter of a
 * request, answer with the same code and address the same field; only the message differs.
 *
 * The depth of the filter is bounded, as it is in the group of the product rows: nesting conditions
 * without limit is a way to make one call cost the portal whatever the client chooses.
 */
final class OwnerRequisiteListFilterStructure extends Structure
{
	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\ListOwnerRequisiteRequest::$filter */
	private const REQUEST_PROPERTY = 'filter';

	/**
	 * The transport wraps the filter into one node, the parser wraps every condition into one more, and a
	 * group of the client is a node between them: two levels of grouping is what this leaves a client, and
	 * two is as deep as a page of one owner has ever needed to be narrowed.
	 */
	private const MAX_DEPTH = 5;

	private function __construct(
		private readonly int $ownerId,
	)
	{
	}

	/**
	 * @throws InvalidFilterException a filter that is not an array, or one nested deeper than a page of
	 *         one owner is narrowed by.
	 * @throws RequiredFieldsInRequestFilterPropertyException the filter does not name the owner.
	 * @throws RequestFilterValidationException the owner is named other than by a plain equality.
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

		$ownerFieldName = self::ownerFieldNameOf($dtoClass);
		$filter = FilterStructure::create($value, $dtoClass, $request);
		self::requireBoundedDepth($filter, 1);

		return new self(self::readOwnerId($filter, $ownerFieldName));
	}

	/**
	 * The owner the page belongs to. Any identifier the client named, a zero and a negative one included:
	 * an owner that is not there is answered as absence by the read, and refusing it here as a malformed
	 * filter would turn one of those two answers into the other.
	 */
	public function getOwnerId(): int
	{
		return $this->ownerId;
	}

	/**
	 * Which of the two names this route publishes the owner under is asked of the contract the route
	 * answers with: the generated DTO carries one of them and the abstract DTO of the group names both
	 * ({@see AbstractOwnerRequisiteDto::ownerFieldName()}).
	 *
	 * @throws \LogicException a request declaring this filter over a contract that is not a requisite of
	 *         an owner. That is a mistake in the declaration of the route rather than anything a client
	 *         could send.
	 */
	private static function ownerFieldNameOf(string $dtoClass): string
	{
		$fields = self::getDto($dtoClass)?->getFields();
		$ownerFieldNames = [
			AbstractOwnerRequisiteDto::OWNER_FIELD_OF_CONTACT,
			AbstractOwnerRequisiteDto::OWNER_FIELD_OF_COMPANY,
		];

		foreach ($ownerFieldNames as $fieldName)
		{
			if (isset($fields[$fieldName]))
			{
				return $fieldName;
			}
		}

		throw new \LogicException('The contract of this route publishes no owner of a requisite: ' . $dtoClass);
	}

	/**
	 * The refusal quotes the group that broke the bound rather than what sits inside it: a condition of a
	 * filter nested too deep is a condition like any other, and a client shown that one would go looking
	 * for what is wrong with the condition instead of with where it put it.
	 *
	 * @throws InvalidFilterException
	 */
	private static function requireBoundedDepth(FilterStructure $node, int $depth): void
	{
		foreach ($node->getConditions() as $condition)
		{
			if (!($condition instanceof FilterStructure))
			{
				continue;
			}

			if ($depth >= self::MAX_DEPTH)
			{
				throw new InvalidFilterException($node->getList());
			}

			self::requireBoundedDepth($condition, $depth + 1);
		}
	}

	/**
	 * @throws RequiredFieldsInRequestFilterPropertyException
	 * @throws RequestFilterValidationException
	 */
	private static function readOwnerId(FilterStructure $filter, string $ownerFieldName): int
	{
		$named = self::findOwnerConditions($filter, $ownerFieldName, self::isPlainNode($filter));

		if ($named === [])
		{
			throw new RequiredFieldsInRequestFilterPropertyException(self::REQUEST_PROPERTY, [$ownerFieldName]);
		}

		if (count($named) > 1)
		{
			throw self::refuseOwnerCondition($ownerFieldName);
		}

		[$condition, $isPlain] = $named[0];
		$ownerId = $condition->getRightOperand();

		if (!$isPlain || $condition->getOperator() !== Operator::Equal || !is_int($ownerId))
		{
			throw self::refuseOwnerCondition($ownerFieldName);
		}

		return $ownerId;
	}

	/**
	 * The owner was named, but not in the one way a page of one owner can be asked for. Addressed at the
	 * field inside the filter, in the notation of the answer, as every refusal of a filter of the CRM is.
	 */
	private static function refuseOwnerCondition(string $ownerFieldName): RequestFilterValidationException
	{
		return new RequestFilterValidationException([
			new Error(
				new LocalizableMessage(
					'CRM_V2_REST_REQUISITE_FILTER_OWNER_MUST_BE_PLAIN_EQUALITY',
					['#FIELD#' => $ownerFieldName],
				),
				self::REQUEST_PROPERTY . '.' . $ownerFieldName,
			),
		]);
	}

	/**
	 * Every condition of the filter that names the owner, wherever it sits: one that names it in a branch
	 * the page is not narrowed by is still a naming of the owner, and answering it as if the field were
	 * missing would send the client looking for the wrong mistake.
	 *
	 * @param bool $isPlain whether the node reached is `and`-ed onto the filter and turned around by no
	 *        negation - which is what makes a condition of it a plain one.
	 * @return array<int, array{0: Condition, 1: bool}> the condition and whether it sits plainly.
	 */
	private static function findOwnerConditions(FilterStructure $node, string $ownerFieldName, bool $isPlain): array
	{
		$named = [];

		foreach ($node->getConditions() as $condition)
		{
			if ($condition instanceof FilterStructure)
			{
				$named = array_merge(
					$named,
					self::findOwnerConditions($condition, $ownerFieldName, $isPlain && self::isPlainNode($condition)),
				);

				continue;
			}

			if ($condition instanceof Condition && $condition->getLeftOperand() === $ownerFieldName)
			{
				$named[] = [$condition, $isPlain];
			}
		}

		return $named;
	}

	private static function isPlainNode(FilterStructure $node): bool
	{
		return $node->logic() === Logic::And && !$node->isNegative();
	}
}
