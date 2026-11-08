<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * An address of a requisite, as the methods of the group answer it inside the requisite it belongs to.
 *
 * The shape is the one the addresses of the new REST CRM already have
 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Lead\AddressDto}), field for field, so a client that can
 * read one address of the CRM can read this one - there is no second format of an address here.
 *
 * WHAT NAMES AN ADDRESS IS ITS TYPE. At most one address of a type belongs to a requisite, so `typeId`
 * is the identity of the address within it. The surrogate identifier of the row is not published: it
 * would be a second way to name a record that is already named, and this group does not create one.
 * Neither is the identifier of the requisite - it is the one the address was read for.
 *
 * READ-ONLY, AND NOT BY OMISSION. Not one field carries {@see \Bitrix\Rest\V3\Attribute\Editable}: no
 * method of the group writes an address, so a client sending one is refused by name rather than having
 * the value quietly dropped. Nothing is filterable or sortable either - an address is answered as part
 * of a requisite and is never the thing a read is addressed to.
 *
 * An address is read whole, so every field below is answered: a cell that is empty is an empty string.
 * The bounds the storage keeps - 1024 for the two address lines, 128 for the city, the region, the
 * province and the country, 16 for the postal code, 100 for the country code - are not stated as rules
 * here, because a rule of a field no request carries could never fire.
 *
 * The anchor coordinates of an address, the mark of the one that is the main one and the link to the
 * address of the geodata module stay inside: they are how the address is stored, not what it is.
 */
final class RequisiteAddressDto extends Dto
{
	/** A value of the dictionary of address types of the CRM, and the identity of this address. */
	public int $typeId;

	public string $address1;

	public string $address2;

	public string $city;

	public string $postalCode;

	public string $region;

	public string $province;

	/** The country as it was typed in; it is not the country of the preset of the requisite. */
	public string $country;

	public string $countryCode;
}
