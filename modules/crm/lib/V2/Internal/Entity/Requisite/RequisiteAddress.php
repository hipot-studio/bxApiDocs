<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

/**
 * An address of a requisite - a record of its own in the address storage the whole CRM shares, rather
 * than a field of the requisite that owns it.
 *
 * What tells two addresses of one requisite apart is the type of the address: the storage keeps at most
 * one address of a type per owner, so `requisiteId` and `typeId` together name a record. The surrogate
 * identifier the row also carries is deliberately absent - it would be a second way to name a record
 * that is already named, and nothing on the way out has a use for it.
 *
 * The service part of the row stays in the storage: the anchor of the address (the owner it was copied
 * from), the mark of the default address and the link to the address of the geo data module all say
 * something about how an address is kept rather than what it is, and are not read at all.
 *
 * An address is always read whole, so an empty cell is an empty string and there is no "not asked"
 * state to tell it from - unlike {@see Requisite}, whose field set the caller chooses.
 *
 * Read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteAddressRepository}
 * is the only place that puts one together.
 *
 * @internal
 */
final readonly class RequisiteAddress
{
	public const FIELD_REQUISITE_ID = 'requisiteId';
	public const FIELD_TYPE_ID = 'typeId';
	public const FIELD_ADDRESS_1 = 'address1';
	public const FIELD_ADDRESS_2 = 'address2';
	public const FIELD_CITY = 'city';
	public const FIELD_POSTAL_CODE = 'postalCode';
	public const FIELD_REGION = 'region';
	public const FIELD_PROVINCE = 'province';
	public const FIELD_COUNTRY = 'country';
	public const FIELD_COUNTRY_CODE = 'countryCode';

	/**
	 * @param int $typeId the kind of address the record is - a value of the dictionary of address types
	 *        ({@see \Bitrix\Crm\EntityAddressType}), and the identity of the address within its requisite.
	 * @param string $country the country as it was typed into the address, which is not the country of
	 *        the preset of the requisite and does not decide anything about the record.
	 */
	public function __construct(
		public int $requisiteId,
		public int $typeId,
		public string $address1,
		public string $address2,
		public string $city,
		public string $postalCode,
		public string $region,
		public string $province,
		public string $country,
		public string $countryCode,
	)
	{
	}
}
