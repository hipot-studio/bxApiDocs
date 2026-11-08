<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\AddressTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteAddress;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\SystemException;

/**
 * The single point the new layer reads the addresses of requisites through.
 *
 * The read is a batch one and only a batch one: the addresses of a whole page of requisites come back in
 * one query, because a page that asks the storage once per record costs as much as the page is long
 * ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteRelationLoader}). Neither legacy nor V2 had
 * such a read - what exists reads the addresses of a single owner
 * ({@see \Bitrix\Crm\EntityAddress::getListByOwner()}) - so the query is written here rather than
 * delegated.
 *
 * The addresses of the whole CRM live in one table, and an address of a requisite is one whose owner type
 * is the requisite itself. The condition is therefore over the owner rather than over the requisite, and
 * it is built in the order the storage indexes it: the identifier of the owner leads (`IX_B_CRM_ADDR_19`),
 * so a page of requisites reads by index however long the list of identifiers is.
 *
 * **Rights are not checked here**, as in the read of requisites themselves
 * ({@see RequisiteRepository}): an address has no access control of its own and the right to read one
 * follows from the right to read the owner of its requisite, which is asked before this class is.
 *
 * Read-only - nothing here writes an address or drops a cache, and the service part of the row is not
 * even read ({@see RequisiteAddress}). ORM objects never leave the class.
 *
 * @internal
 */
final class RequisiteAddressRepository
{
	/** Every field of an address of a requisite and the column of `b_crm_addr` behind it. */
	private const COLUMN_BY_FIELD = [
		RequisiteAddress::FIELD_REQUISITE_ID => 'ENTITY_ID',
		RequisiteAddress::FIELD_TYPE_ID => 'TYPE_ID',
		RequisiteAddress::FIELD_ADDRESS_1 => 'ADDRESS_1',
		RequisiteAddress::FIELD_ADDRESS_2 => 'ADDRESS_2',
		RequisiteAddress::FIELD_CITY => 'CITY',
		RequisiteAddress::FIELD_POSTAL_CODE => 'POSTAL_CODE',
		RequisiteAddress::FIELD_REGION => 'REGION',
		RequisiteAddress::FIELD_PROVINCE => 'PROVINCE',
		RequisiteAddress::FIELD_COUNTRY => 'COUNTRY',
		RequisiteAddress::FIELD_COUNTRY_CODE => 'COUNTRY_CODE',
	];

	private const INT_FIELDS = [
		RequisiteAddress::FIELD_REQUISITE_ID => true,
		RequisiteAddress::FIELD_TYPE_ID => true,
	];

	/**
	 * The addresses of the requisites $requisiteIds names, all of them, in one query.
	 *
	 * The order is fixed rather than left to the storage: the addresses of one requisite come back by
	 * type, so the same request answers the same order twice. Grouping the flat answer by
	 * {@see RequisiteAddress::$requisiteId} is the caller's - the length of the list of identifiers is
	 * its decision too, and the query is written to be given a part of a page rather than a portal.
	 *
	 * @param int[] $requisiteIds a value that could not be an identifier is dropped rather than refused,
	 *        and an empty list answers an empty page: the caller hands over a page it has just read, and a
	 *        page with nothing on it is an answer rather than a mistake.
	 * @return RequisiteAddress[]
	 * @throws SystemException a failure of the query itself.
	 */
	public function findByRequisiteIds(array $requisiteIds): array
	{
		$ids = array_values(array_unique(array_filter($requisiteIds, static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		$rows = AddressTable::query()
			->setSelect(array_values(self::COLUMN_BY_FIELD))
			->where('ENTITY_TYPE_ID', OwnerType::REQUISITE)
			->whereIn('ENTITY_ID', $ids)
			->setOrder([
				'ENTITY_ID' => 'ASC',
				'TYPE_ID' => 'ASC',
			])
			->fetchAll()
		;

		$addresses = [];
		foreach ($rows as $row)
		{
			$addresses[] = self::createAddress($row);
		}

		return $addresses;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createAddress(array $row): RequisiteAddress
	{
		$fields = [];
		foreach (self::COLUMN_BY_FIELD as $field => $column)
		{
			$value = $row[$column] ?? null;
			$fields[$field] = isset(self::INT_FIELDS[$field]) ? (int)$value : (string)$value;
		}

		// The field names of an address are its property names by contract, so a row becomes a record by
		// name rather than by the order of the map above.
		return new RequisiteAddress(...$fields);
	}
}
