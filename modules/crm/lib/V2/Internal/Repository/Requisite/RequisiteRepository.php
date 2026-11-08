<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\Condition;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The single point the new layer reads the requisites of the portal through: the selection that spans
 * the whole portal ({@see self::findAll()}), one record ({@see self::findById()}) and the records of a
 * single owner ({@see self::findByOwner()}).
 *
 * **Rights are not checked here** - by any of the three, and the gate each of them stands behind is a
 * different one. The selection that spans the portal is gated by the portal administrator check of the
 * REST controller above it: a requisite carries no access control of its own, and the right to read one
 * is derived from the right to read its owner - which that selection deliberately does not apply. Put it
 * behind anything weaker than a portal administrator and the legal details of every counterparty of the
 * portal are open.
 *
 * The read of one owner is gated by the right to read **that owner**, asked before this class is
 * ({@see \Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions::canRead()}). One question is the
 * whole of it, because the answer is bound to a single owner and cannot carry a record of another one.
 * The condition built for lists that cross owners ({@see \Bitrix\Crm\Security\OwnerEntityListRestriction})
 * is deliberately **not** added on top of it: it exists for a selection whose rows belong to owners the
 * caller did not name, and here it would ask a question already answered and make every page pay for a
 * subquery that decides nothing.
 *
 * Owners are limited to contacts and companies. That belongs to the contract of the read rather than
 * to the storage, so it is applied here and cannot be widened by the caller.
 *
 * Read-only: a requisite is written through {@see RequisiteWriteRepository}, and the two are kept apart
 * so that a read never carries a way to change what it read. They share the map of the columns
 * ({@see RequisiteColumnMap}) and nothing else. There is no count of the whole selection either - the
 * answer is a page, and an incomplete page is what tells the caller the selection has ended.
 *
 * ORM objects never leave the class, and neither does the storage vocabulary: the filter, the
 * ordering and the field set are all expressed in {@see Requisite} field names, and a name this class
 * does not know is refused rather than quietly dropped.
 *
 * @internal
 */
final class RequisiteRepository
{
	/**
	 * Every field of a requisite record and the column of `b_crm_requisite` behind it.
	 *
	 * Declared in {@see RequisiteColumnMap} and named here: the write of the same area asks the storage
	 * the same question, and two maps of it would answer differently the first time a field was added to
	 * one of them.
	 */
	private const COLUMN_BY_FIELD = RequisiteColumnMap::COLUMN_BY_FIELD;

	/**
	 * The owners a requisite of this read belongs to; nothing else is answered for. Public because it is
	 * part of the contract of the read rather than an implementation detail: the transport above refuses an
	 * owner type the client names, and it must refuse by this list rather than by a copy of it.
	 */
	public const OWNER_TYPE_IDS = [
		OwnerType::CONTACT,
		OwnerType::COMPANY,
	];

	// The three maps below are declared in RequisiteColumnMap for the same reason as the map of columns,
	// and cover the whole area rather than the read alone: a field only the write knows is never a field
	// this class is asked to make sense of.

	private const INT_FIELDS = RequisiteColumnMap::INT_FIELDS;

	private const BOOL_FIELDS = RequisiteColumnMap::BOOL_FIELDS;

	private const DATE_TIME_FIELDS = RequisiteColumnMap::DATE_TIME_FIELDS;

	/**
	 * A page of requisites of the contacts and companies of the portal, ordered as asked. The filter,
	 * the ordering and the page are all applied in SQL: nothing is dropped after the fact, so the page
	 * and the order stay what the caller asked for.
	 *
	 * @param ConditionTree|null $filter conditions whose columns are {@see Requisite} field names. The
	 *        owner types of the read are added to it here and are not the caller's to widen.
	 * @param array<string, string> $order {@see Requisite} field name => `ASC`/`DESC`; the primary key
	 *        settles whatever the ordering leaves equal, so the pages of one read do not overlap.
	 * @param string[] $select the {@see Requisite} fields the caller needs; an empty list means all of
	 *        them. A field left out is not read from the storage at all, and the record carries `null` in
	 *        its place, which is indistinguishable from a cell the record keeps empty.
	 * @return Requisite[]
	 * @throws ArgumentException a filter, an ordering or a field set over a field that is not a field
	 *         of a requisite.
	 * @throws SystemException a failure of the query itself, or a storage that answers a value of a
	 *         field the read cannot make sense of.
	 */
	public function findAll(
		?ConditionTree $filter = null,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $select = [],
	): array
	{
		$query = RequisiteTable::query()->setSelect(self::translateColumns($select));

		if ($filter !== null && $filter->hasConditions())
		{
			$query->where(self::translateFilter($filter));
		}
		$query->whereIn(self::COLUMN_BY_FIELD[Requisite::FIELD_OWNER_TYPE_ID], self::OWNER_TYPE_IDS);

		$query->setOrder(self::translateOrder($order));

		if ($limit !== null)
		{
			$query->setLimit($limit);
			$query->setOffset($offset);
		}

		$requisites = [];
		foreach ($query->fetchAll() as $row)
		{
			$requisites[] = self::createRequisite($row);
		}

		return $requisites;
	}

	/**
	 * The requisite $id names, and `null` when there is none - including for an identifier that could
	 * not be one, so a caller never has to check the identifier before asking.
	 *
	 * A record of an owner outside the types of the read is `null` as well: the storage holds such rows
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Requisite\OwnerResolver::isSupportedType()}), and this class
	 * answers for none of them however it is asked.
	 *
	 * @param string[] $select the {@see Requisite} fields the caller needs; an empty list means all of
	 *        them, and a field left out is `null` in the record - indistinguishable from a cell the
	 *        record keeps empty.
	 * @throws ArgumentException a field set over a field that is not a field of a requisite.
	 * @throws SystemException a failure of the query itself, or a storage that answers a value of a
	 *         field the read cannot make sense of.
	 */
	public function findById(int $id, array $select = []): ?Requisite
	{
		if ($id <= 0)
		{
			return null;
		}

		$requisites = $this->findAll(
			(new ConditionTree())->where(Requisite::FIELD_ID, $id),
			[],
			1,
			0,
			$select,
		);

		return $requisites[0] ?? null;
	}

	/**
	 * A page of the requisites of one owner, ordered as asked.
	 *
	 * Whether the user may read that owner is settled before this is called, and an owner of a type no
	 * requisite of this read belongs to answers an empty page rather than a refusal: the types are the
	 * contract of the read and naming another one selects nothing, exactly as a filter over them would.
	 *
	 * @param array<string, string> $order {@see Requisite} field name => `ASC`/`DESC`; the primary key
	 *        settles whatever the ordering leaves equal, so the pages of one read do not overlap.
	 * @param string[] $select the {@see Requisite} fields the caller needs; an empty list means all of
	 *        them.
	 * @return Requisite[]
	 * @throws ArgumentException an ordering or a field set over a field that is not a field of a
	 *         requisite.
	 * @throws SystemException a failure of the query itself, or a storage that answers a value of a
	 *         field the read cannot make sense of.
	 */
	public function findByOwner(
		int $ownerTypeId,
		int $ownerId,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $select = [],
	): array
	{
		if ($ownerId <= 0)
		{
			return [];
		}

		$filter = (new ConditionTree())
			->where(Requisite::FIELD_OWNER_TYPE_ID, $ownerTypeId)
			->where(Requisite::FIELD_OWNER_ID, $ownerId)
		;

		return $this->findAll($filter, $order, $limit, $offset, $select);
	}

	/**
	 * The columns to load: the ones behind the asked-for fields, and the identifier whether it was
	 * asked for or not - the page is keyed by it.
	 *
	 * @param string[] $select
	 * @return string[]
	 * @throws ArgumentException
	 */
	private static function translateColumns(array $select): array
	{
		if ($select === [])
		{
			return array_values(self::COLUMN_BY_FIELD);
		}

		$columns = [self::COLUMN_BY_FIELD[Requisite::FIELD_ID]];
		foreach ($select as $field)
		{
			$columns[] = self::requireColumn($field);
		}

		return array_values(array_unique($columns));
	}

	/**
	 * The same tree with requisite field names replaced by the columns behind them. The caller keeps
	 * its own tree untouched: a request may build it once and a rewrite in place would be visible to
	 * it.
	 *
	 * @throws ArgumentException
	 */
	private static function translateFilter(ConditionTree $filter): ConditionTree
	{
		$translated = clone $filter;
		self::translateTreeColumns($translated);

		return $translated;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function translateTreeColumns(ConditionTree $tree): void
	{
		foreach ($tree->getConditions() as $condition)
		{
			if ($condition instanceof ConditionTree)
			{
				self::translateTreeColumns($condition);

				continue;
			}

			$column = $condition instanceof Condition ? $condition->getColumn() : null;
			if (!is_string($column))
			{
				throw new ArgumentException('Unsupported condition of a requisite filter', 'filter');
			}

			$condition->setColumn(self::requireColumn($column));
		}
	}

	/**
	 * @param array<string, string> $order
	 * @return array<string, string> never empty: the primary key settles the order of the requisites a
	 *         requested ordering leaves equal, so a page is repeatable.
	 * @throws ArgumentException
	 */
	private static function translateOrder(array $order): array
	{
		$translated = [];
		foreach ($order as $field => $direction)
		{
			$translated[self::requireColumn($field)] = $direction;
		}

		$translated[self::COLUMN_BY_FIELD[Requisite::FIELD_ID]] ??= 'ASC';

		return $translated;
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireColumn(string $field): string
	{
		return RequisiteColumnMap::requireColumn($field);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function createRequisite(array $row): Requisite
	{
		$fields = [];
		foreach (self::COLUMN_BY_FIELD as $field => $column)
		{
			$fields[$field] = self::castValue($field, $row[$column] ?? null);
		}
		$fields[Requisite::FIELD_ID] = (int)$fields[Requisite::FIELD_ID];

		// The field names of a requisite are its property names by contract, so a row becomes a record
		// by name: writing seventy-four values out one by one would be a third list to keep in step
		// with the map above and the constructor.
		return new Requisite(...$fields);
	}

	/**
	 * @throws SystemException a value of a field of a requisite the read cannot make sense of.
	 */
	private static function castValue(string $field, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		if (isset(self::INT_FIELDS[$field]))
		{
			return (int)$value;
		}

		if (isset(self::BOOL_FIELDS[$field]))
		{
			// The column keeps a letter and the ORM declares the field boolean, so what comes back
			// depends on the converter having been applied. Both answers say the same thing.
			return $value === true || $value === 'Y';
		}

		if (isset(self::DATE_TIME_FIELDS[$field]))
		{
			if (!$value instanceof DateTime)
			{
				// The converter of the ORM answers a moment in time for these columns. Anything else is a
				// storage the read has to be taught rather than an empty cell to pass on: every record would
				// answer `createdTime` and `updatedTime` as `null`, which a caller cannot tell from a cell
				// that was never filled.
				throw new SystemException(
					sprintf('Unexpected value of the requisite field `%s`: %s', $field, get_debug_type($value)),
				);
			}

			return $value;
		}

		return (string)$value;
	}
}
