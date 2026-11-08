<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractOwnerRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\GetOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\ListOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectedFields;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;

/**
 * Translates the parts of a request over the requisites of one owner into the arguments the public read
 * of the domain takes ({@see RequisiteProvider}).
 *
 * The entity type of the route is the state of the mapper rather than an argument of every call: it is
 * the trusted invariant the route carries, it is what decides the name the owner is published under
 * ({@see AbstractOwnerRequisiteDto::ownerFieldName()}), and a requisite means nothing without it.
 *
 * ONE NAME CROSSES THE BOUNDARY, AND IT IS THE OWNER. The contract of the group and the fields of
 * {@see Requisite} are one vocabulary, name for name - `inn` is `inn` on both sides - except for the
 * owner, which the route publishes as `contactId` or `companyId` and the domain calls `ownerId`. So the
 * whole of the translation done here is that one name, and everything else is carried across as written.
 * That is also why the translation cannot be skipped: a client may well ask for `contactId` in `select`,
 * and the read would not know the name.
 *
 * No rule of the subject is applied here: whether the owner exists, whether the user may read it and
 * what a preset makes of a field are the domain's to answer, and this class only says what was asked.
 */
final class OwnerRequisiteRequestMapper
{
	public function __construct(
		private readonly EntityType $entityType,
	)
	{
	}

	/**
	 * The owner whose page was asked for.
	 *
	 * A DEGENERATE TRANSLATION, AND DELIBERATELY SO. The contract of the group declares one filterable
	 * field and it is the owner ({@see AbstractOwnerRequisiteDto}), so a condition over anything else
	 * never reaches this class - the framework refuses an undeclared field of a filter as the request is
	 * parsed - and the way the owner may be named is settled by
	 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\OwnerRequisiteListFilterStructure}
	 * before that. What is left for a filter of this group to become is a single number, and there is no
	 * condition here that a read could fail to express.
	 */
	public function mapOwnerId(ListOwnerRequisiteRequest $request): int
	{
		return $request->filter->getOwnerId();
	}

	/**
	 * The ordering of a page, in the names {@see RequisiteProvider::getListByOwner()} takes.
	 *
	 * Carried across unchanged, and the two reasons it can be are worth stating: the vocabularies of the
	 * contract and of the domain differ in the owner alone, and the owner is not sortable, so no name
	 * that reaches here is one of the two. The framework has already refused a field the contract does
	 * not declare sortable ({@see \Bitrix\Rest\V3\Structure\Ordering\OrderStructure::create()}), so what
	 * arrives is a field the read knows.
	 *
	 * No tie-breaker is appended: the read settles whatever an ordering leaves equal by the key of the
	 * record itself, and a second place to keep in step would only be a place to fall out of step.
	 *
	 * @return array<string, string> {@see Requisite} field name => `ASC`/`DESC`; empty when the client
	 *         asked for no order of its own.
	 */
	public function mapOrder(?ItemListOrderStructure $order): array
	{
		return $order?->getList() ?? [];
	}

	/**
	 * The window of a page. The framework caps the size from above and refuses a zero, but lets a
	 * negative one through, so the lower bound is settled here - the same place the administrative list
	 * of requisites settles it ({@see RequisiteListRequestMapper::mapPager()}). A request that named no
	 * window gets the default page of the framework: an unbounded external read is not on offer.
	 *
	 * @throws InvalidPaginationException a window no page can be read through.
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
	 * What the answer is to be made of, in the names of the contract: the scalar fields of the requisite
	 * and the fields asked for inside each of its collections. The rule is the one the whole of the new
	 * REST of the CRM reads by ({@see RequestSelectedFields}), and the fallback of this group is the key
	 * of the record - what a client that asked for nothing, or for a collection alone, is answered under.
	 *
	 * The answer of this method is what both the read and {@see OwnerRequisiteDtoMapper} are given: the
	 * read loads the fields and the relations named here, and the mapper of the answer fills those and
	 * no others.
	 */
	public function mapSelect(GetOwnerRequisiteRequest|ListOwnerRequisiteRequest $request): SelectedFields
	{
		return RequestSelectedFields::resolveStructured($request, Requisite::id);
	}

	/**
	 * The fields of {@see Requisite} the read is to load, translated out of the names of the contract.
	 *
	 * @return string[] never empty.
	 */
	public function mapSelectedFields(SelectedFields $selectedFields): array
	{
		$ownerFieldName = AbstractOwnerRequisiteDto::ownerFieldName($this->entityType);

		return array_map(
			static fn(string $fieldName): string => $fieldName === $ownerFieldName
				? Requisite::ownerId
				: $fieldName,
			$selectedFields->getScalarFields(),
		);
	}

	/**
	 * The relations of a requisite the read is to load - {@see Requisite::addresses} and
	 * {@see Requisite::bankRequisites}, and the contract of the group publishes no third one. A relation
	 * the client did not name is not read at all: each of them costs a read of its own.
	 *
	 * The names need no translating - a collection is called the same on both sides - but the vocabulary
	 * is crossed here all the same, so that the controller asks one place what a request turns into.
	 *
	 * @return string[]
	 */
	public function mapSelectedRelations(SelectedFields $selectedFields): array
	{
		return array_keys($selectedFields->getNestedFields());
	}
}
