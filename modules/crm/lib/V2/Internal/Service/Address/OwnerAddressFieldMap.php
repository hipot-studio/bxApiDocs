<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\V2\Public\Entity\Address\Address;

/**
 * The correspondence between the fields of {@see Address} and the columns a stored row keeps
 * them in.
 *
 * Both directions live in one place: a read turns a row into address values, a write turns address values
 * into fields of a row, and neither side keeps a table of storage
 * columns of its own.
 *
 * Absence is an empty value on both sides, the way the address value expresses it: a column that holds no
 * value reads as an empty string, and a field nobody filled is written as an empty one.
 *
 * @internal
 */
final class OwnerAddressFieldMap
{
	/** Storage column by the field name of {@see Address::toArray()}. */
	private const COLUMNS = [
		'address1' => 'ADDRESS_1',
		'address2' => 'ADDRESS_2',
		'city' => 'CITY',
		'postalCode' => 'POSTAL_CODE',
		'region' => 'REGION',
		'province' => 'PROVINCE',
		'country' => 'COUNTRY',
		'countryCode' => 'COUNTRY_CODE',
	];

	/**
	 * @param array<string, mixed> $values Values by the names of {@see Address::toArray()}; anything
	 *        else, the identity of the address included, is dropped.
	 * @return array<string, string> Address fields of a row.
	 */
	public static function toStoredFields(array $values): array
	{
		$fields = [];
		foreach (self::COLUMNS as $field => $column)
		{
			$fields[$column] = (string)($values[$field] ?? '');
		}

		return $fields;
	}

	/**
	 * The same correspondence for a partial write: a field the values do not mention is left out of the
	 * answer instead of becoming an empty one. That is what a change is made of - what is not named keeps
	 * whatever is stored, and only a field named with an empty value blanks it.
	 *
	 * @param array<string, mixed> $values Values by the names of {@see Address::toArray()}; anything
	 *        else, the identity of the address included, is dropped.
	 * @return array<string, string> Address fields of a row, only the named ones.
	 */
	public static function toStoredChanges(array $values): array
	{
		$fields = [];
		foreach (self::COLUMNS as $field => $column)
		{
			if (array_key_exists($field, $values))
			{
				$fields[$column] = (string)$values[$field];
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $row Row as the storage keeps it.
	 * @return array<string, string> Address fields by the names of {@see Address::toArray()}.
	 */
	public static function toAddressValues(array $row): array
	{
		$values = [];
		foreach (self::COLUMNS as $field => $column)
		{
			$values[$field] = (string)($row[$column] ?? '');
		}

		return $values;
	}
}
