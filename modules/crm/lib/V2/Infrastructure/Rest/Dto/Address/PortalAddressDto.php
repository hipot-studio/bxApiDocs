<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of an address of the CRM, as the administrative read of the whole portal
 * publishes it.
 *
 * The owner is the anchor of the row - the contact, the company or the lead the address belongs to in the
 * terms of the CRM - and it is published under the very names the per-owner groups already use. The record
 * the row is kept on is a different owner and is not published as such: of it only
 * {@see self::$requisiteId} leaves the portal, and only where a requisite is what keeps the address.
 *
 * The eight text fields carry the names the published groups of the address already gave them, letter for
 * letter ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressDto},
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Lead\AddressDto}): one notion of the CRM is named
 * one way across the whole REST. The owner is where this contract parts with them on purpose - a route of
 * one owner publishes it as `contactId` or `companyId`, because the type is the invariant of that route,
 * while a read that spans the portal has no such invariant and answers the type alongside the identifier.
 *
 * The composition is kept by
 * {@see \Bitrix\Crm\Tests\Unit\Lib\V2\Infrastructure\Rest\Dto\Address\PortalAddressDtoTest}: a field added
 * here on its own is a breach of the published contract, and a published field can never be removed.
 *
 * No property carries {@see \Bitrix\Rest\V3\Attribute\Editable}: the only method behind this contract
 * reads. No property is nullable either, as in the address contracts already published - an unfilled text
 * column of the storage is answered as an empty string by the read below the transport.
 *
 * `Filterable` and `Sortable` are closed lists and start narrow: widening them later is compatible,
 * narrowing them is not. A field is filterable only when its column stands in an index the read can use
 * together with the condition over the owner type it always adds - which is why not one of the text fields
 * is (their columns stand third in every index that leads with the anchor, and the method is not obliged
 * to carry a condition over the type of the address), and `countryCode` is not even in an index of its own.
 * The one field sorted by is the identifier: the type of the address leads no index the identifier is part
 * of, so an ordering by it would end up sorting the whole selection in memory.
 */
final class PortalAddressDto extends Dto
{
	/**
	 * The identifier of the row, unique across the portal. It survives a change of the values of the
	 * address and a move onto another record, but not a change of the type of the address: that is carried
	 * out as a delete and an insert, and the row comes back under a new identifier.
	 */
	#[Filterable]
	#[Sortable]
	public int $id;

	/** @see \Bitrix\Crm\EntityAddressType a value of the dictionary `crm.dictionary.addressType.*`. */
	#[Filterable]
	public int $typeId;

	/** @see \Bitrix\Crm\V2\Public\OwnerType only a lead, a contact or a company. */
	#[Filterable]
	public int $ownerTypeId;

	/**
	 * The identifier of the owner, unique only together with {@see self::$ownerTypeId}: a filter naming
	 * this field alone answers with the owners of every served type that carry the number.
	 */
	#[Filterable]
	public int $ownerId;

	/**
	 * The requisite the address is kept on, and zero when the record behind the row is not a requisite -
	 * the ordinary case of an address of a lead.
	 *
	 * The field stands for no column of its own: it is computed from the record of the row. Neither
	 * filtered nor sorted by for that reason - a condition would have to be translated into a pair of
	 * columns - and both can be opened later compatibly.
	 */
	public int $requisiteId;

	/** Up to 1024 characters, as the storage keeps it. */
	public string $address1;

	/** Up to 1024 characters, as the storage keeps it. */
	public string $address2;

	/** Up to 128 characters, as the storage keeps it. */
	public string $city;

	/** Up to 16 characters, as the storage keeps it. */
	public string $postalCode;

	/** Up to 128 characters, as the storage keeps it. */
	public string $region;

	/** Up to 128 characters, as the storage keeps it. */
	public string $province;

	/** Up to 128 characters, as the storage keeps it. */
	public string $country;

	/** Up to 100 characters, as the storage keeps it. */
	public string $countryCode;
}
