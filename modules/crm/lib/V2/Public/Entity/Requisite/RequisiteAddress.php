<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

/**
 * An address of a requisite - a record of its own in the address storage the whole CRM shares, rather
 * than a field of the requisite that owns it.
 *
 * What tells two addresses of one requisite apart is the type of the address: at most one address of a
 * type belongs to a requisite, so the type is the identity of the address within it. The surrogate
 * identifier of the row is deliberately absent - it would be a second way to name a record that is
 * already named. So is the identifier of the requisite: it is the one this address was read for.
 *
 * READ-ONLY, AND NOT BY OMISSION. No command of the group writes an address: a requisite is created,
 * changed and deleted through {@see Requisite}, and its addresses are maintained elsewhere. A caller
 * that needs to change one is not looking at an unfinished contract but at a different one.
 *
 * An address is always read whole, so a cell that is empty is an empty string and there is no "was not
 * asked for" state to tell it from - unlike a requisite, whose field set the caller chooses.
 */
final readonly class RequisiteAddress
{
	/**
	 * @param int $typeId the kind of address the record is - a value of the dictionary of address types
	 *        of the CRM, and the identity of the address within its requisite.
	 * @param string $country the country as it was typed into the address. It is not the country of the
	 *        preset of the requisite and decides nothing about the record.
	 */
	public function __construct(
		private int $typeId,
		private string $address1,
		private string $address2,
		private string $city,
		private string $postalCode,
		private string $region,
		private string $province,
		private string $country,
		private string $countryCode,
	)
	{
	}

	public function getTypeId(): int
	{
		return $this->typeId;
	}

	public function getAddress1(): string
	{
		return $this->address1;
	}

	public function getAddress2(): string
	{
		return $this->address2;
	}

	public function getCity(): string
	{
		return $this->city;
	}

	public function getPostalCode(): string
	{
		return $this->postalCode;
	}

	public function getRegion(): string
	{
		return $this->region;
	}

	public function getProvince(): string
	{
		return $this->province;
	}

	public function getCountry(): string
	{
		return $this->country;
	}

	public function getCountryCode(): string
	{
		return $this->countryCode;
	}
}
