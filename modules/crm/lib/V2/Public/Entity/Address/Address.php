<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Address;

final class Address
{
	private const FIELDS = [
		'address1',
		'address2',
		'city',
		'postalCode',
		'region',
		'province',
		'country',
		'countryCode',
	];

	private array $values;

	private array $changedFields = [];

	public function __construct()
	{
		$this->values = array_fill_keys(self::FIELDS, '');
	}

	public static function fromArray(array $values): self
	{
		$address = new self();
		foreach (self::FIELDS as $field)
		{
			$address->values[$field] = (string)($values[$field] ?? '');
		}

		return $address;
	}

	public static function changesFromArray(array $values): self
	{
		$address = new self();
		foreach (self::FIELDS as $field)
		{
			if (array_key_exists($field, $values))
			{
				$address->set($field, (string)$values[$field]);
			}
		}

		return $address;
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

	public function getChangedFields(): array
	{
		return $this->changedFields;
	}

	public function applyChanges(self $changes): self
	{
		$address = clone $this;
		foreach ($changes->getChangedFields() as $field => $value)
		{
			$address->values[$field] = $value;
		}

		return $address;
	}

	public function toArray(): array
	{
		return $this->values;
	}

	public function isEmpty(): bool
	{
		$values = $this->values;
		unset($values['countryCode']);

		return !array_filter($values, static fn(string $value): bool => $value !== '');
	}

	private function set(string $field, string $value): self
	{
		$this->values[$field] = $value;
		$this->changedFields[$field] = $value;

		return $this;
	}
}
