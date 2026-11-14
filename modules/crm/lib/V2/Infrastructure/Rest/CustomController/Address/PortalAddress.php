<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractPortalAdminController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\PortalAddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\AddressListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\PortalAddressDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\ListAddressRequest;
use Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\Validation\RequestFilterValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * `crm.address.list` - the addresses of the leads, contacts and companies of the whole portal, read by a
 * portal administrator.
 *
 * The subject is the address as a row of its own rather than a field of the owner it hangs on: the owner
 * is answered alongside the row instead of being the thing the method is addressed through. A filter over
 * the owner is therefore ordinary and optional, unlike the address groups of one contact or one company.
 *
 * Who may call it is decided by {@see AbstractPortalAdminController} before the action runs, and that gate
 * is the whole reason the method reads without asking about the owner of a single row: the addresses of
 * every counterparty of the portal are answered here, and the right to read one address is otherwise
 * derived from the right to read its owner.
 *
 * The name is the one the legacy method of the same resource carries, and that is not a collision: the two
 * live at different addresses and answer different fields, and neither the fields nor the filter of the
 * legacy method are restated here - what this one publishes is a contract of its own. The legacy handler
 * keeps serving the name it always served; not a line of it is touched by this method.
 *
 * The route names no owner type, so it has no service parameters of its own and the source parameters of
 * the action are the client's query string alone. Nothing is read from there - the whole request is the
 * typed body, and the answer is the standard list.
 *
 * The read goes to {@see AddressRepository} of `V2\Internal` directly: the scenario is a single read of
 * the addresses of the portal, and a public contract of the domain would be a refactoring out of all
 * proportion to it. The dependency stays inside REST and carries no compatibility promise.
 *
 * ### What the answer holds, and what a client should know about it
 *
 * The method answers the contents of the address table. An address whose owner is no longer on the portal
 * comes back like any other: cleaning up rows left behind is not what this method is for, and telling a
 * portal administrator that such rows exist is rather the point of it. Deleting an owner the ordinary way
 * does not produce that state anyway - the recycle bin moves the address under a suspended anchor type,
 * which this method does not serve at all.
 *
 * A whole portal is exported by walking the identifier: `filter: ['id', '>', the last one read]` under
 * `order: {id: 'ASC'}`. A deep offset makes the storage walk every skipped row, so the price of a full
 * pass over an offset grows with the square of the number of rows. The one caveat of the walk: a row
 * whose address type changed between two neighbouring pages gets a new identifier - the change is carried
 * out as a delete and an insert - and can therefore be read twice or missed.
 *
 * A filter narrows the answer but not the work below it. The order by identifier is served by the
 * primary key alone, so under a filter the storage either sorts everything that matched it or walks
 * the identifier past everything that did not. Carrying the boundary of the walk into a filtered pass
 * as well - `filter: ['id', '>', the last one read]` next to the rest of the filter - keeps it as
 * cheap as an unfiltered one.
 */
#[DtoType(PortalAddressDto::class)]
final class PortalAddress extends AbstractPortalAdminController
{
	/**
	 * The page as the repository reads it. Of the refusals the read makes, none is the client's: the
	 * framework rejects a field without `Filterable` or `Sortable` long before this, and a derived field
	 * asked for in `select` is expanded into its sources by the read itself, so an {@see ArgumentException}
	 * from below is a DTO and a repository that disagree - a defect of the portal, left to the framework
	 * for a system error with the failure in the log. Answering it as a malformed request would tell the
	 * client to fix something it did not write.
	 *
	 * @throws InvalidFilterException an operand an address cannot be filtered by.
	 * @throws RequestFilterValidationException an owner of a type the method does not serve.
	 * @throws InvalidPaginationException a page below the bounds the framework leaves unchecked.
	 * @throws ArgumentException a filter, an ordering or a field set over a field that is not a field of an
	 *         address.
	 */
	public function listAction(ListAddressRequest $request): ListResponse
	{
		$requestMapper = new AddressListRequestMapper();
		$selectedFields = $requestMapper->mapSelect($request);
		$pager = $requestMapper->mapPager($request->pagination);
		$rows = (new AddressRepository())->findAll(
			$requestMapper->mapFilter($request->filter),
			$requestMapper->mapOrder($request->order),
			$pager->getLimit(),
			$pager->getOffset(),
			$selectedFields,
		);

		return new ListResponse(
			(new PortalAddressDtoMapper())->getDtoCollectionByRows($rows, $selectedFields),
		);
	}
}
