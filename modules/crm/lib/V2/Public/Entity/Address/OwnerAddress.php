<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Address;

/**
 * An address of a contact or a company.
 *
 * Such an address is never stored on its owner: it lives on one of the requisites of the owner, and the
 * owner itself is kept next to it. The object therefore carries two owners and they are not
 * interchangeable - {@see self::getRequisiteId()} is the record the address is stored on, while
 * {@see self::getOwnerTypeId()} with {@see self::getOwnerId()} is the contact or the company the address
 * belongs to. A row of a lead has no requisite behind it and is a different type altogether
 * ({@see LeadAddress}).
 *
 * The address of a lead and the address of an owner are read the same way and written the same way, so
 * this class repeats the shape of its neighbour. Identity - the identifier of the row, the type of the
 * address and both owners - is filled once by {@see self::fromArray()} and never changes afterwards; only
 * the address fields have setters.
 *
 * A setter both stores the value and marks the field as changed, and {@see self::getChangedFields()}
 * answers with the marked ones alone. That is what a partial write is made of: an object carrying only
 * the fields a caller has touched goes into a change command, and a field nobody touched keeps whatever
 * is stored.
 *
 * Absence is expressed by an empty value and never by `null`: an identifier nobody has assigned yet is
 * `0` and a field nobody has filled is an empty string. An object built by a caller for a write is
 * therefore a valid object of this class with an empty identity, not a half-built one.
 */
final class OwnerAddress
{
	private const IDENTITY_FIELDS = [
		'id',
		'typeId',
		'requisiteId',
		'ownerTypeId',
		'ownerId',
	];

	private const ADDRESS_FIELDS = [
		'address1',
		'address2',
		'city',
		'postalCode',
		'region',
		'province',
		'country',
		'countryCode',
	];

	/**
	 * The address fields a value of an address is made of, {@see self::isEmpty()}.
	 */
	private const FIELDS_MAKING_AN_ADDRESS = [
		'address1',
		'address2',
		'city',
		'postalCode',
		'region',
		'province',
		'country',
	];

	/** @var array<string, int> */
	private array $identity;

	/** @var array<string, string> */
	private array $values;

	/** @var array<string, string> */
	private array $changedFields = [];

	public function __construct()
	{
		$this->identity = array_fill_keys(self::IDENTITY_FIELDS, 0);
		$this->values = array_fill_keys(self::ADDRESS_FIELDS, '');
	}

	/**
	 * An address built from the values of {@see self::toArray()}: what is missing stays empty, what is
	 * unknown is dropped, and a negative identifier is no identifier at all.
	 *
	 * The result carries no changes: it describes an address, it does not ask for one to be written.
	 *
	 * @param array<string, mixed> $values
	 */
	public static function fromArray(array $values): self
	{
		$address = new self();

		foreach (self::IDENTITY_FIELDS as $field)
		{
			$address->identity[$field] = max(0, (int)($values[$field] ?? 0));
		}

		foreach (self::ADDRESS_FIELDS as $field)
		{
			$address->values[$field] = (string)($values[$field] ?? '');
		}

		return $address;
	}

	/**
	 * Identifier of the stored row, `0` for an address that was never written.
	 */
	public function getId(): int
	{
		return $this->identity['id'];
	}

	/**
	 * Type of the address - a value of the address type dictionary of CRM.
	 */
	public function getTypeId(): int
	{
		return $this->identity['typeId'];
	}

	/**
	 * Requisite of the owner the address is stored on, `0` when the address is not kept on one.
	 */
	public function getRequisiteId(): int
	{
		return $this->identity['requisiteId'];
	}

	/**
	 * Type of the CRM owner of the address - a contact or a company.
	 */
	public function getOwnerTypeId(): int
	{
		return $this->identity['ownerTypeId'];
	}

	public function getOwnerId(): int
	{
		return $this->identity['ownerId'];
	}

	public function getAddress1(): string
	{
		return $this->values['address1'];
	}

	public function getAddress2(): string
	{
		return $this->values['address2'];
	}

	public function getCity(): string
	{
		return $this->values['city'];
	}

	public function getPostalCode(): string
	{
		return $this->values['postalCode'];
	}

	public function getRegion(): string
	{
		return $this->values['region'];
	}

	public function getProvince(): string
	{
		return $this->values['province'];
	}

	public function getCountry(): string
	{
		return $this->values['country'];
	}

	public function getCountryCode(): string
	{
		return $this->values['countryCode'];
	}

	public function setAddress1(string $value): self
	{
		return $this->set('address1', $value);
	}

	public function setAddress2(string $value): self
	{
		return $this->set('address2', $value);
	}

	public function setCity(string $value): self
	{
		return $this->set('city', $value);
	}

	public function setPostalCode(string $value): self
	{
		return $this->set('postalCode', $value);
	}

	public function setRegion(string $value): self
	{
		return $this->set('region', $value);
	}

	public function setProvince(string $value): self
	{
		return $this->set('province', $value);
	}

	public function setCountry(string $value): self
	{
		return $this->set('country', $value);
	}

	public function setCountryCode(string $value): self
	{
		return $this->set('countryCode', $value);
	}

	/**
	 * The address fields a caller has set, by the names of {@see self::toArray()}. A field that was
	 * assigned the value it already held counts as set: what is asked for is a write of that value, and
	 * whether the write changes anything is not a question of this object.
	 *
	 * @return array<string, string>
	 */
	public function getChangedFields(): array
	{
		return $this->changedFields;
	}

	/**
	 * Identity and address fields together, ready for {@see self::fromArray()}. The changes are not part
	 * of it: they are a request to write, not a value of the address.
	 *
	 * @return array<string, int|string>
	 */
	public function toArray(): array
	{
		return array_merge($this->identity, $this->values);
	}

	/**
	 * Whether no field an address is made of holds a value, {@see self::FIELDS_MAKING_AN_ADDRESS}. The
	 * identity is not part of the answer: a stored address of which every field was blanked is empty all
	 * the same.
	 *
	 * Leaving the code of the country out is a decision and not an oversight. The code names the country
	 * of an address that is already there instead of standing for a place of its own - nothing can be
	 * reached or shown on a map by two letters alone - so a value carrying nothing else describes no
	 * address. Every path that stores an address of an owner asks by this same rule and refuses such a
	 * value as an empty address; a value calling itself an address that no path would store would be the
	 * worse of the two answers.
	 */
	public function isEmpty(): bool
	{
		foreach (self::FIELDS_MAKING_AN_ADDRESS as $field)
		{
			if ($this->values[$field] !== '')
			{
				return false;
			}
		}

		return true;
	}

	private function set(string $field, string $value): self
	{
		$this->values[$field] = $value;
		$this->changedFields[$field] = $value;

		return $this;
	}
}
