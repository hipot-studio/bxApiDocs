<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\OwnerRequisiteListFilterStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * `crm.contact.requisite.list` and `crm.company.requisite.list`: a page of the requisites of one owner.
 *
 * The filter is not optional, and the property says so by being typed without a default - that is how
 * {@see Request::create()} is told that a request without it is incomplete. The owner inside it is
 * required by {@see OwnerRequisiteListFilterStructure}, which also explains why the attribute of the
 * framework cannot state it on a route of this group. A missing filter and a filter that names no owner
 * are two different answers, and both are refusals of the form of the request.
 *
 * The owner does not appear beside the filter. A requisite is a child of the record it describes, the
 * parent of a list belongs in its filter, and a scalar of its own next to the filter is the shape this
 * group deliberately does not repeat.
 *
 * The selection is the one of the CRM ({@see SelectStructure}): a requisite carries collections - its
 * addresses and its bank details - and each of them costs a read of its own, so a page answers one only
 * when the client names it ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\RequestSelectedFields}).
 *
 * Neither the page nor its lower bound is restated here: the group introduces no field of its own for
 * paging, a cursor or a total. The framework caps the size from above and lets a negative one through,
 * and the one place that refuses it is where the page becomes a parameter of the read.
 */
final class ListOwnerRequisiteRequest extends Request
{
	public ?SelectStructure $select = null;

	public OwnerRequisiteListFilterStructure $filter;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;
}
