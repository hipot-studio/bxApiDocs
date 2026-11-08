<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Address;

use Bitrix\Crm\AddressTable;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Address\AddressPlacement;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * The single point the new layer reads and writes `b_crm_addr` through.
 *
 * Two different owners live in one row and must not be confused. `ENTITY_TYPE_ID + ENTITY_ID` is the
 * owner of the record itself - for a contact or a company that is their requisite, not the contact or
 * the company. `ANCHOR_TYPE_ID + ANCHOR_ID` is the CRM owner the address belongs to. Reading by owner
 * therefore goes through the anchor columns, while updating and deleting address a known row by the
 * composite key of the record.
 *
 * Rows leave the class as plain column-keyed arrays; ORM objects never do.
 *
 * Every read is neutral and returns whatever the table holds. Access checks belong to the scenario that
 * resolves the logical owner before exposing a row.
 *
 * @internal
 */
final class AddressRepository
{
	private const ANCHOR_OWNER_TYPES = [CCrmOwnerType::Lead, CCrmOwnerType::Contact, CCrmOwnerType::Company];

	private const KEY_FIELDS = ['TYPE_ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'];

	private const WRITABLE_FIELDS = [
		'TYPE_ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'ANCHOR_TYPE_ID',
		'ANCHOR_ID',
		'ADDRESS_1',
		'ADDRESS_2',
		'CITY',
		'POSTAL_CODE',
		'REGION',
		'PROVINCE',
		'COUNTRY',
		'COUNTRY_CODE',
		'LOC_ADDR_ID',
		'IS_DEF',
	];

	private const SELECT = ['ID', ...self::WRITABLE_FIELDS];

	private const TEXT_FIELDS = [
		'ADDRESS_1',
		'ADDRESS_2',
		'CITY',
		'POSTAL_CODE',
		'REGION',
		'PROVINCE',
		'COUNTRY',
		'COUNTRY_CODE',
	];

	private const INT_FIELDS = [
		'ID',
		'TYPE_ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'ANCHOR_TYPE_ID',
		'ANCHOR_ID',
		'LOC_ADDR_ID',
	];

	/**
	 * One row by its own numeric identifier, without any permission restriction: the caller needs the
	 * anchor of the row to decide whether it may see it at all.
	 *
	 * @return array<string, mixed>|null Column-keyed row of {@see self::SELECT}, `null` when there is
	 *         no such row. Numeric columns come back as int, `IS_DEF` as bool, text columns as stored.
	 */
	public function findById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		$row = AddressTable::query()
			->setSelect(self::SELECT)
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;

		return $row ? self::normalizeRow($row) : null;
	}

	/**
	 * One row by the composite key of the record, without any permission restriction: the write path asks
	 * either for a row it has already located by other means, or - for an owner that keeps its addresses
	 * on itself - for the row of this very address type, which the key alone identifies. Either way it has
	 * to get the same answer for an agent, an import and a call made without a user.
	 *
	 * @return array<string, mixed>|null Column-keyed row of {@see self::SELECT}, `null` when there is
	 *         no such row.
	 */
	public function findByKey(int $typeId, int $entityTypeId, int $entityId): ?array
	{
		$row = AddressTable::query()
			->setSelect(self::SELECT)
			->setFilter(self::buildPrimary($typeId, $entityTypeId, $entityId))
			->setLimit(1)
			->fetch()
		;

		return $row ? self::normalizeRow($row) : null;
	}

	public function findByIdentifier(AddressIdentifier $identifier): ?array
	{
		return $this->findByKey($identifier->typeId, $identifier->recordTypeId, $identifier->recordId);
	}

	public function findByPlacement(AddressPlacement $placement): ?array
	{
		return $this->findByIdentifier($placement->getIdentifier());
	}

	/** @return array<int, array<string, mixed>> */
	public function findAllByRecord(int $recordTypeId, int $recordId): array
	{
		if ($recordTypeId <= 0 || $recordId <= 0)
		{
			return [];
		}

		$rows = AddressTable::query()
			->setSelect(self::SELECT)
			->where('ENTITY_TYPE_ID', $recordTypeId)
			->where('ENTITY_ID', $recordId)
			->fetchAll()
		;

		return array_map(self::normalizeRow(...), $rows);
	}

	/**
	 * All addresses of one CRM owner without a permission restriction.
	 *
	 * The read names no order of its own. The set of one owner is small and the consumer of it sorts the
	 * rows in memory anyway, finishing every order with the identifier of the row, so ordering here would
	 * be the same rows sorted twice - and by a column the index over the anchor gives no order by.
	 *
	 * @return array<int, array<string, mixed>> Rows of {@see self::SELECT}, in no particular order.
	 */
	public function findAllByAnchorOwner(int $anchorTypeId, int $anchorId): array
	{
		if ($anchorId <= 0 || !in_array($anchorTypeId, self::ANCHOR_OWNER_TYPES, true))
		{
			return [];
		}

		$filter = [
			'=ANCHOR_TYPE_ID' => $anchorTypeId,
			'=ANCHOR_ID' => $anchorId,
		];

		$rows = AddressTable::query()
			->setSelect(self::SELECT)
			->setFilter($filter)
			->fetchAll()
		;

		return array_map(self::normalizeRow(...), $rows);
	}

	/**
	 * The requisite of the owner already holding the default address of the type, or 0 when there is
	 * none. Ties are broken the way the owner sorts its requisites: `SORT` first, identifier second.
	 *
	 * Unlike {@see self::findAllByAnchorOwner()} this read applies no permission restriction at all, and
	 * its result must never be handed to a user as a list of addresses. It answers a question of the
	 * write path - where an address of this owner and type already lives - and the write path has to get
	 * the same answer for an agent, an import and a call made without a user, where a restriction would
	 * silently move the address onto another requisite.
	 */
	public function findRequisiteOfDefaultAddressWithoutPermissionCheck(
		int $anchorTypeId,
		int $anchorId,
		int $typeId,
	): int
	{
		if ($anchorId <= 0)
		{
			return 0;
		}

		$requisitesHoldingTheAddress = AddressTable::query()
			->setSelect(['ENTITY_ID'])
			->where('TYPE_ID', $typeId)
			->where('ENTITY_TYPE_ID', CCrmOwnerType::Requisite)
			->where('ANCHOR_TYPE_ID', $anchorTypeId)
			->where('ANCHOR_ID', $anchorId)
			->where('IS_DEF', true)
		;

		// An address row carries no order of its own, so the order comes from the requisites it points
		// to. Reading the requisites also drops rows left pointing at a requisite that no longer exists.
		$row = RequisiteTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $requisitesHoldingTheAddress)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}

	/**
	 * Inserts a new row and returns its identifier.
	 *
	 * Insert, not merge: merging by the unique key belongs to {@see self::merge()}, the write of the
	 * legacy path, where an address of an already occupied type is silently overwritten.
	 *
	 * @param array<string, mixed> $fields Columns of {@see self::WRITABLE_FIELDS}; anything else is
	 *        dropped, `ID` included - it is assigned by the database.
	 * @return Result Data of a successful result: `['ID' => int]`.
	 */
	public function add(array $fields): Result
	{
		$result = new Result();

		$addResult = AddressTable::add(self::prepareFieldsToStore($fields));
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		// The identifier comes from the operation and not from the connection: the connection answers
		// with the last insert made on it, which any subscriber of the insert event may have made in a
		// table of its own. The map has no autoincrement of its own - its primary is the composite key -
		// so the operation reports the inserted row by a primary of one guessed column, and a primary of
		// three columns means the storage named no identifier at all.
		$primary = $addResult->getPrimary();
		$id = is_array($primary) && count($primary) === 1 ? (int)$addResult->getId() : 0;

		if ($id <= 0)
		{
			// Whether the insert names an identifier at all is a question of the driver, not of this row:
			// a connection returning the last insert of the session answers it, while one appending
			// `RETURNING` needs a column named in the map - and this map names none. The row is stored
			// either way, so the identifier is read back by the key that addresses it.
			$storedRow = $this->findByKey(
				(int)$fields['TYPE_ID'],
				(int)$fields['ENTITY_TYPE_ID'],
				(int)$fields['ENTITY_ID'],
			);
			$id = (int)($storedRow['ID'] ?? 0);
		}

		return $result->setData(['ID' => $id]);
	}

	/**
	 * Merges the row addressed by its composite key in one statement: it is inserted when the key is
	 * free and rewritten when it is taken, and either way the row keeps the identifier it has.
	 *
	 * This is the write of the legacy path, where an address of an already occupied type is silently
	 * overwritten. Asking the unique key that question with an insert and a caught duplicate instead
	 * would take two statements over one index record and hold the locks of both until the commit.
	 * A caller that has to learn about the taken key writes through {@see self::add()}.
	 *
	 * The merge names no identifier of the row it wrote, and no caller of this line asks for one. A
	 * storage refusing the statement leaves as an exception, exactly as it did on the legacy path.
	 *
	 * @param array<string, mixed> $fields Columns of {@see self::WRITABLE_FIELDS}.
	 */
	public function merge(array $fields): void
	{
		AddressTable::upsert(self::prepareFieldsToStore($fields));
	}

	/**
	 * Rewrites an existing row addressed by its composite key. The key itself is immutable and is
	 * dropped from the payload.
	 *
	 * @param array<string, mixed> $fields Columns of {@see self::WRITABLE_FIELDS}.
	 */
	public function update(int $typeId, int $entityTypeId, int $entityId, array $fields): Result
	{
		$fields = array_diff_key(self::prepareFieldsToStore($fields), array_flip(self::KEY_FIELDS));

		$updateResult = AddressTable::update(
			self::buildPrimary($typeId, $entityTypeId, $entityId),
			$fields,
		);

		return (new Result())->addErrors($updateResult->getErrors());
	}

	/**
	 * Deletes an existing row addressed by its composite key.
	 */
	public function delete(int $typeId, int $entityTypeId, int $entityId): Result
	{
		$deleteResult = AddressTable::delete(self::buildPrimary($typeId, $entityTypeId, $entityId));

		return (new Result())->addErrors($deleteResult->getErrors());
	}

	/**
	 * Makes the addresses of one record the default ones among the records of the same owner: the rows
	 * of the given record keep the flag, the rows of its siblings lose it. A zero address type moves the
	 * flag for every type at once.
	 *
	 * This is a single statement over the rows of every record of one owner, and no other operation of
	 * the class addresses more than one row: the flag is a property of the whole set, and recalculating
	 * it row by row would leave the set without a default one in between.
	 */
	public function setDefaultAmongOwnerRecords(int $typeId, int $entityTypeId, int $entityId): void
	{
		AddressTable::setDef([
			'TYPE_ID' => $typeId,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
		]);
	}

	/**
	 * @return array<string, int>
	 */
	private static function buildPrimary(int $typeId, int $entityTypeId, int $entityId): array
	{
		return [
			'TYPE_ID' => $typeId,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
		];
	}

	/**
	 * The columns the table takes, with an empty text value turned into no value at all.
	 *
	 * A blank column of the table has held a null and not an empty string since the merge of the old write
	 * path, and this is where that conversion belongs: the caller keeps the row it built, empty strings and
	 * all, and only what goes into the table is stripped of them.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function prepareFieldsToStore(array $fields): array
	{
		$fields = array_intersect_key($fields, array_flip(self::WRITABLE_FIELDS));

		foreach (self::TEXT_FIELDS as $field)
		{
			if (($fields[$field] ?? null) === '')
			{
				$fields[$field] = null;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalizeRow(array $row): array
	{
		foreach (self::INT_FIELDS as $field)
		{
			$row[$field] = (int)$row[$field];
		}

		$row['IS_DEF'] = (bool)$row['IS_DEF'];

		return $row;
	}
}
