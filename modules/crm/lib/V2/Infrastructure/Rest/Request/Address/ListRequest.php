<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\OwnerAddressListFilterStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * `crm.{contact|company}.address.list`: the addresses of one owner.
 *
 * An address means nothing outside the contact or the company it belongs to, so the filter is not optional:
 * the property is typed and has no default, which is how {@see Request::create()} is told that a request
 * without it is incomplete. What the filter may say is settled by
 * {@see OwnerAddressListFilterStructure}: the owner by plain equality, optionally the type of the address
 * the same way, and nothing else - a set of owners, a range of types and an `or` of conditions are refused
 * rather than narrowed, and that structure also explains why the framework attribute
 * {@see FilterRequired} cannot state the requirement of the owner here. A missing filter and a filter that
 * names no owner are two different answers, and both are validation errors of the request.
 *
 * The owner does not appear beside the filter. An address is a child resource, the parent of a list belongs
 * in its filter, and a scalar of its own next to a list request is a shape this group deliberately does not
 * take.
 *
 * `select` is not published, for the reason it is not published by {@see GetRequest}: the group answers the
 * whole of an address. The order is the one the read of the domain has
 * ({@see \Bitrix\Crm\V2\Public\Provider\Address\OwnerAddressProvider::getList()}), and which fields it may
 * name is the contract's - the framework refuses every field the DTO does not declare sortable. The set of
 * addresses of one owner is a handful, so the page of the framework needs no ceiling of its own.
 */
final class ListRequest extends Request
{
	public OwnerAddressListFilterStructure $filter;

	public ?OrderStructure $order = null;

	public ?PaginationStructure $pagination = null;
}
