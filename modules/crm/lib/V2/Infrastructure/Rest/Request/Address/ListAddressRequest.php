<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListOrderStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\ItemListPaginationStructure;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\SelectStructure;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * `crm.address.list`.
 *
 * The standard shape of a list request, with every part optional. The method reads the addresses of the
 * whole portal, so it has no owner to name and no filter to require, unlike the address groups of one
 * owner - and unlike them it cannot use
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\OwnerAddressListFilterStructure} either: that
 * structure demands an owner and admits equality alone, while this method answers a filter of any shape
 * the standard structure of REST v3 expresses.
 *
 * The name is `ListAddressRequest` rather than `ListRequest` because the latter is taken in this namespace
 * by the group of one owner.
 *
 * What the standard filter can express and the read cannot - an expression as an operand - and the one
 * subject refusal of the filter - an owner of a type the method does not serve - both belong to
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\AddressListRequestMapper}, where the filter
 * becomes a parameter of the read. The page size is not restated here either: the framework caps it from
 * above and refuses a non-positive one, and the mapper keeps a bound of its own for the pagination it
 * builds when the client asked for none.
 */
final class ListAddressRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	public ?ItemListOrderStructure $order = null;

	public ?ItemListPaginationStructure $pagination = null;
}
