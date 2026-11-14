<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Address;

use Bitrix\Main\ArgumentException;

/**
 * The one place that knows which column of `b_crm_addr` is behind a field of an address.
 *
 * The read of the addresses of one owner and the read that spans the portal both need this, and each of
 * them holding its own copy is the one failure this class exists to prevent: a column renamed or a field
 * added on one side alone would leave the two answering different questions about the same row, and
 * nothing would say so until a value went missing.
 *
 * The vocabulary is field names - the names the request mapper, the published DTO and the internal types
 * all speak. Storage names never leave the repositories of the area.
 *
 * @internal
 */
final class AddressColumnMap
{
	/**
	 * The address itself: the fields {@see \Bitrix\Crm\V2\Public\Entity\Address\Address} is made of.
	 *
	 * Named apart from the whole map because one caller needs exactly this half:
	 * {@see \Bitrix\Crm\V2\Internal\Service\Address\OwnerAddressFieldMap} walks the map to turn address
	 * values into a row and back, and the identity of the row is not an address value of any of them.
	 */
	public const TEXT_COLUMN_BY_FIELD = [
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
	 * Every field an address row is read by and the column behind it.
	 *
	 * Two different owners live in one row and are named apart. `ownerTypeId`/`ownerId` is the CRM owner
	 * the address belongs to; `recordTypeId`/`recordId` is the record that keeps it - for a contact or a
	 * company that is their requisite, not the contact or the company.
	 */
	public const COLUMN_BY_FIELD = [
		'id' => 'ID',
		'typeId' => 'TYPE_ID',
		'ownerTypeId' => 'ANCHOR_TYPE_ID',
		'ownerId' => 'ANCHOR_ID',
		'recordTypeId' => 'ENTITY_TYPE_ID',
		'recordId' => 'ENTITY_ID',
	] + self::TEXT_COLUMN_BY_FIELD;

	/**
	 * The fields that stand for no column of their own and the fields they are computed from. Asking for
	 * one loads its sources; turning them back into it belongs to whoever publishes it.
	 */
	public const DERIVED_FIELDS = [
		'requisiteId' => ['recordTypeId', 'recordId'],
	];

	/**
	 * Keyed by field name rather than listed: every field of every row of a page is classified through it,
	 * and a page holds up to a thousand rows.
	 */
	public const INT_FIELDS = [
		'id' => true,
		'typeId' => true,
		'ownerTypeId' => true,
		'ownerId' => true,
		'recordTypeId' => true,
		'recordId' => true,
	];

	/**
	 * Every field a row is read by. A derived field is not one of them: it is a field of the published
	 * contract rather than of the storage.
	 *
	 * @return string[]
	 */
	public static function allFields(): array
	{
		return array_keys(self::COLUMN_BY_FIELD);
	}

	/**
	 * The columns to load for $fields, with a derived field replaced by the fields it is computed from.
	 *
	 * @param string[] $fields
	 * @return string[]
	 * @throws ArgumentException a name that is not a field of an address.
	 */
	public static function expandToColumns(array $fields): array
	{
		$columns = [];
		foreach ($fields as $field)
		{
			foreach (self::DERIVED_FIELDS[$field] ?? [$field] as $source)
			{
				$columns[] = self::requireColumn($source);
			}
		}

		return array_values(array_unique($columns));
	}

	/**
	 * @throws ArgumentException a name that is not a field of an address, the derived ones included: they
	 *         stand for no column and cannot be filtered or ordered by.
	 */
	public static function requireColumn(string $field): string
	{
		$column = self::COLUMN_BY_FIELD[$field] ?? null;
		if ($column === null)
		{
			throw new ArgumentException(sprintf('Unknown address field `%s`', $field), 'field');
		}

		return $column;
	}
}
