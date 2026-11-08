<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Dictionary;

use Bitrix\Crm\EO_Status;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryField;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilter;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilterCondition;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryFilterOperator;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionarySort;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryValue;
use Bitrix\Crm\V2\Internal\Exception\Dictionary\DictionaryValueGoneException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

/**
 * Reads through a query on the status table rather than through the facade. The reading methods of the
 * facade cannot serve a page: one of them loads a whole dictionary at a time
 * ({@see \CCrmStatus::GetStatus()}) and the other is deprecated, builds raw SQL with no limit at all
 * and orders by the record key descending ({@see \CCrmStatus::GetList()}).
 *
 * Writes go the other way round. Creating and changing a value go through {@see \CCrmStatus}, because
 * the normalization of a value lives in that facade and writing past it would mean a second set of
 * rules for the same rows. Erasing goes through {@see StatusTable::delete()} by the record key, which
 * is what `\CCrmStatus::Delete()` is deprecated in favour of. One change goes past the facade as well
 * ({@see pastBoundary()}): a value standing at a position the facade replaces instead of keeping cannot
 * be changed through it without being moved. Those four and the read above are every way this class
 * touches storage.
 *
 * A write fills the name and the position, and on a creation the identifier it was given. Nothing else
 * ever reaches the boundary: the dictionary is what the facade is built on rather than a field of a
 * write, the shipped mark is read-only in this domain, the original name of a shipped value is what the
 * interface shows and must not be overwritten, and a written semantics turns a dictionary into one that
 * refuses ordinary values.
 *
 * Every write is held to one at a time per value written ({@see serialized()}): all three of them check
 * storage before they touch it, and the table enforces nothing that would make the check hold.
 *
 * The caches of the table are not touched here - neither the managed one nor the static one. A write
 * purges the managed cache of the whole table on its own, and the `onAfter*` handlers of the table drop
 * the static cache of the dictionary that was written, so there is nothing left for a read to arrange.
 *
 * @internal
 */
final class DictionaryValueRepository implements DictionaryValueRepositoryInterface
{
	/**
	 * Columns every read selects. The record key is among them and is not one of the four published
	 * fields: it is the last key of every order and the address a write of this boundary needs
	 * ({@see findRecord()}).
	 */
	private const SELECT = ['ID', 'STATUS_ID', 'NAME', 'SORT', 'SYSTEM'];

	/** Column the dictionary a value belongs to is kept in. */
	private const TYPE_COLUMN = 'ENTITY_ID';

	/** Column the internal record key is kept in. */
	private const RECORD_KEY_COLUMN = 'ID';

	public function __construct(
		private readonly DictionaryValueLockInterface $lock = new DictionaryValueLock(),
	)
	{
	}

	public function getById(DictionaryType $type, string $id): ?DictionaryValue
	{
		$record = $this->findRecord($type, $id);

		return $record === null ? null : self::toValue($record);
	}

	/**
	 * @return list<DictionaryValue>
	 */
	public function getList(
		DictionaryType $type,
		?DictionaryFilter $filter = null,
		?DictionarySort $sort = null,
		?PagerInterface $pager = null,
	): array
	{
		$query = self::queryOf($type)->setOrder(self::orderOf($sort));

		if ($filter !== null)
		{
			// As a subfilter of the query, so that the logic of the tree stays inside it: a tree of its own
			// meaning "A or B" and the condition selecting the dictionary have to end up as
			// "dictionary AND (A or B)".
			$query->where(self::toConditionTree($filter));
		}

		if ($pager !== null)
		{
			$query
				->setLimit($pager->getLimit())
				->setOffset($pager->getOffset())
			;
		}

		$values = [];
		foreach ($query->fetchCollection() as $record)
		{
			$values[] = self::toValue($record);
		}

		return $values;
	}

	public function add(DictionaryType $type, ?string $id, string $name, ?int $sort): Result
	{
		$fields = [DictionaryField::Name->getColumn() => $name];

		// Left out rather than passed as nothing: the boundary reads an absent position as its own
		// default one, and an absent identifier as the one it is about to generate. `'0'` is read as an
		// absent identifier too - the boundary asks `empty()` of it - and the scenario above refuses it
		// before a write gets here, because there is no way to make the boundary store it as given.
		if ($sort !== null)
		{
			$fields[DictionaryField::Sort->getColumn()] = $sort;
		}

		if (($id ?? '') === '')
		{
			// No pair to hold a section by: the identifier is made up inside the boundary, which looks its
			// own candidates up ({@see \CCrmStatus::getUniqueRandomStatusId()}). That check stays as
			// unserialized as it has always been - see {@see serialized()}.
			return $this->created($type, $fields);
		}

		$fields[DictionaryField::Id->getColumn()] = $id;

		return $this->serialized($type, (string)$id, fn (): Result => $this->created($type, $fields));
	}

	public function update(DictionaryType $type, string $id, ?string $name, ?int $sort): Result
	{
		return $this->serialized($type, $id, fn (): Result => $this->changed($type, $id, $name, $sort));
	}

	public function delete(DictionaryType $type, string $id): Result
	{
		return $this->serialized($type, $id, fn (): Result => $this->erased($type, $id));
	}

	/**
	 * The creation itself: the checks and the insert of the facade, and the created value read back.
	 *
	 * @param array<string, mixed> $fields columns of the table
	 */
	private function created(DictionaryType $type, array $fields): Result
	{
		$boundary = new \CCrmStatus($type->value);

		try
		{
			$recordId = $boundary->Add($fields);
		}
		catch (SystemException $exception)
		{
			if ($exception::class !== SystemException::class)
			{
				// A failed query above all: it says the storage of the portal is broken and not that the
				// write was turned down, so it travels as it is and is answered for as a failure.
				throw $exception;
			}

			// The one exception of the boundary that is its own refusal: it gives up generating an
			// identifier after five collisions and raises this very class
			// ({@see \CCrmStatus::getUniqueRandomStatusId()}). Nothing has been written by then.
			return self::refusal([$exception->getMessage()]);
		}

		if ($recordId === false)
		{
			return self::refusalOf($boundary);
		}

		return $this->written($type, (int)$recordId);
	}

	/**
	 * The change itself, the row it addresses read inside the same critical section as the write.
	 *
	 * @throws DictionaryValueGoneException
	 */
	private function changed(DictionaryType $type, string $id, ?string $name, ?int $sort): Result
	{
		$record = $this->requireRecord($type, $id);
		$recordId = (int)$record->getId();
		$currentSort = (int)$record->getSort();

		$fields = [];
		if ($name !== null)
		{
			$fields[DictionaryField::Name->getColumn()] = $name;
		}

		// The boundary writes the position on every change and puts its own default in place of one it was
		// not given - and in place of one of zero or less as well ({@see \CCrmStatus::Update()}). So a
		// change that leaves the position alone has to carry the current one, taken from the row the value
		// was addressed by rather than from a second read of it, and it can carry it only while the
		// boundary would keep it.
		if ($sort !== null || $currentSort > 0)
		{
			$fields[DictionaryField::Sort->getColumn()] = $sort ?? $currentSort;

			return $this->throughBoundary($type, $recordId, $fields);
		}

		return $this->pastBoundary($type, $recordId, $fields);
	}

	/**
	 * The erasure itself, the row it addresses read inside the same critical section as the statement.
	 *
	 * @throws DictionaryValueGoneException
	 */
	private function erased(DictionaryType $type, string $id): Result
	{
		$record = $this->requireRecord($type, $id);

		$result = StatusTable::delete($record->getId());
		if (!$result->isSuccess())
		{
			// A refused deletion erases nothing: the ORM collects the refusal of `onBeforeDelete` and
			// returns before the statement.
			return self::refusal($result->getErrorMessages());
		}

		return new Result();
	}

	/**
	 * $write with the writes of the value $id of the dictionary $type held to one at a time.
	 *
	 * Every write of this boundary reads storage before it changes it - the facade asks whether the
	 * identifier is free before it inserts ({@see \CCrmStatus::CheckStatusId()}), and a change and an
	 * erasure read the row they address before they touch it - and the storage makes none of those reads
	 * hold: the index over the pair (dictionary, identifier) is not a unique one. Two writes interleaving
	 * between the read and the statement therefore store two rows under one published identifier, or leave
	 * a change writing over a row an erasure has already taken. A transaction on its own would not help:
	 * with no constraint to break there is nothing for it to refuse, and the row another writer inserts is
	 * not a row an isolation level of the storage hides.
	 *
	 * The lock is the pair and not the dictionary or the table, so callers configuring different values
	 * never wait for each other. It holds the writers of this boundary and nobody else: everything else of
	 * the portal writing the same table - the administrative interface of the dictionaries above all -
	 * takes no lock and is not held back by this one, so the reads above are serialized rather than
	 * guaranteed.
	 */
	private function serialized(DictionaryType $type, string $id, callable $write): Result
	{
		if (!$this->lock->acquire($type, $id))
		{
			// A refusal of the boundary like any other: nothing has been written, and the text is for the
			// journal and not for a client
			// ({@see \Bitrix\Crm\V2\Internal\Service\Dictionary\StorageRefusalMapper}).
			return self::refusal([
				"Another write of value {$id} of dictionary {$type->value} is still running.",
			]);
		}

		try
		{
			return $write();
		}
		finally
		{
			$this->lock->release($type, $id);
		}
	}

	/**
	 * A change through the facade, which is where the normalization of a value lives. The position is part
	 * of $fields already: the facade writes one on every change.
	 *
	 * @param array<string, mixed> $fields columns of the table
	 */
	private function throughBoundary(DictionaryType $type, int $recordId, array $fields): Result
	{
		$boundary = new \CCrmStatus($type->value);
		$boundary->Update($recordId, $fields);

		// The answer of the boundary says nothing: it is the record key whatever happened
		// ({@see \CCrmStatus::Update()}). The text of the last error is the only place a refusal is left
		// in, and the next operation of the same facade overwrites it, so it is read right here.
		if ((string)$boundary->GetLastError() !== '')
		{
			return self::refusalOf($boundary);
		}

		return $this->written($type, $recordId);
	}

	/**
	 * A change of a value standing at a position the facade would not keep - zero or less, which is a row
	 * this domain never creates and the table does hold. Through the facade such a change would move the
	 * value to the default position, and a caller changing the name alone would find it in another place
	 * of the list. So it is written through the table, which touches the columns it is given and nothing
	 * else, and the position is not one of them.
	 *
	 * The normalization of the facade is not lost with it: the only rules it has for these columns are the
	 * position it would replace here and a name it trims and drops when empty, and the name a write of this
	 * domain carries has been through both above ({@see \Bitrix\Crm\V2\Internal\Service\Dictionary\DictionaryValueService}).
	 *
	 * A change naming no field at all writes nothing: there is nothing left to write once the position is
	 * out, and the value is already what it is asked to become.
	 *
	 * @param array<string, mixed> $fields columns of the table, the position not among them
	 */
	private function pastBoundary(DictionaryType $type, int $recordId, array $fields): Result
	{
		if ($fields === [])
		{
			return $this->written($type, $recordId);
		}

		$result = StatusTable::update($recordId, $fields);
		if (!$result->isSuccess())
		{
			// A refused change writes nothing: the ORM collects the refusal of `onBeforeUpdate` and returns
			// before the statement.
			return self::refusal($result->getErrorMessages());
		}

		return $this->written($type, $recordId);
	}

	/**
	 * The stored row of the value $id of $type, the internal record key with it.
	 *
	 * This is where a write of this boundary gets the address of the row from - {@see StatusTable::delete()}
	 * takes the record key, and so does {@see \CCrmStatus::Update()} - and the row travels no further:
	 * above this class a value is the pair (dictionary, identifier).
	 *
	 * No order and one row: a dictionary holding the identifier twice is an invariant this domain
	 * declares, and which of the rows answers is then not defined.
	 */
	private function findRecord(DictionaryType $type, string $id): ?EO_Status
	{
		return self::queryOf($type)
			->where(DictionaryField::Id->getColumn(), $id)
			->setLimit(1)
			->fetchObject()
		;
	}

	/**
	 * A query on one dictionary and never on the table as a whole. The condition selecting the
	 * dictionary is not an input: a caller names the dictionary, and the domain filter has no field for
	 * it at all.
	 */
	private static function queryOf(DictionaryType $type): Query
	{
		return StatusTable::query()
			->setSelect(self::SELECT)
			->where(self::TYPE_COLUMN, $type->value)
		;
	}

	/**
	 * @return array<string, string> column of the table => direction, the record key last
	 */
	private static function orderOf(?DictionarySort $sort): array
	{
		$order = [];
		foreach (($sort ?? new DictionarySort())->getOrder() as $field => $direction)
		{
			$order[DictionaryField::from($field)->getColumn()] = $direction;
		}

		// Values sharing the value of the ordered field would otherwise come in an order the storage does
		// not promise, and a page of such an order can show one value twice and another not at all.
		$order[self::RECORD_KEY_COLUMN] = DictionarySort::DIRECTION_ASC;

		return $order;
	}

	/**
	 * The condition tree of the query behind a domain filter: a condition per condition, a group per
	 * group, and a negation where the caller put it.
	 */
	private static function toConditionTree(DictionaryFilter $filter): ConditionTree
	{
		$tree = Query::filter();
		$tree->logic($filter->isOr() ? ConditionTree::LOGIC_OR : ConditionTree::LOGIC_AND);
		$tree->negative($filter->isNegative());

		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof DictionaryFilter)
			{
				$tree->where(self::toConditionTree($condition));

				continue;
			}

			self::applyCondition($tree, $condition);
		}

		return $tree;
	}

	/**
	 * One comparison of the domain as a condition of the same query.
	 *
	 * The stored form of a value is spelled out nowhere here: the value travels as the domain has it -
	 * `true` and `false` of `isSystem` included - and the field of the ORM entity turns it into what the
	 * column keeps ({@see StatusTable::getMap()} declares `SYSTEM` with `'N'`/`'Y'`). A comparison by
	 * `isSystem` is therefore the lexicographic comparison of those two letters, which is the declared
	 * behaviour of this domain.
	 */
	private static function applyCondition(ConditionTree $tree, DictionaryFilterCondition $condition): void
	{
		if ($condition->operator->expectsList() && $condition->value === [])
		{
			// A set with nothing in it is a condition nothing satisfies, and the ORM would build `IN ()`
			// out of it. It stays where the caller put it instead of emptying the whole answer: inside a
			// negation or an `or` group, a condition matching nothing is not a query matching nothing.
			$tree->whereExpr('1 = 0', []);

			return;
		}

		$tree->where(
			$condition->field->getColumn(),
			self::queryOperatorOf($condition->operator),
			$condition->value,
		);
	}

	/**
	 * The operator of the query behind a domain operator. Spelled out rather than passed through, so
	 * that an operator added to the domain without a translation is refused here by
	 * {@see \UnhandledMatchError} instead of reaching the query as an unknown one.
	 */
	private static function queryOperatorOf(DictionaryFilterOperator $operator): string
	{
		return match ($operator)
		{
			DictionaryFilterOperator::Equal => '=',
			DictionaryFilterOperator::NotEqual => '!=',
			DictionaryFilterOperator::Greater => '>',
			DictionaryFilterOperator::GreaterOrEqual => '>=',
			DictionaryFilterOperator::Less => '<',
			DictionaryFilterOperator::LessOrEqual => '<=',
			DictionaryFilterOperator::In => 'in',
			DictionaryFilterOperator::Between => 'between',
		};
	}

	/**
	 * The row of the value a write is about to change or erase.
	 *
	 * A value that is not there is not an outcome of the operation: the scenario above has just read the
	 * value to answer whether it exists, and the answer of this domain to a value it has none of is given
	 * there. What is left is a value erased after that read - by another request, or by anything else of
	 * the portal writing the same table - and the scenario recognizes that case by the class of the
	 * refusal and answers it as a value that is not there.
	 *
	 * @throws DictionaryValueGoneException
	 */
	private function requireRecord(DictionaryType $type, string $id): EO_Status
	{
		$record = $this->findRecord($type, $id);
		if ($record === null)
		{
			throw new DictionaryValueGoneException($type->value, $id);
		}

		return $record;
	}

	private function written(DictionaryType $type, int $recordId): Result
	{
		return (new Result())->setData([
			self::DATA_KEY_VALUE => $this->requireStored($type, $recordId),
		]);
	}

	/**
	 * The result of a write read back from storage instead of assembled from the input: the boundary
	 * generates the identifier of a new value, puts a position of its own in place of one it was not
	 * given, and trims the name it stores.
	 */
	private function requireStored(DictionaryType $type, int $recordId): DictionaryValue
	{
		$record = self::queryOf($type)
			->where(self::RECORD_KEY_COLUMN, $recordId)
			->setLimit(1)
			->fetchObject()
		;

		if ($record === null)
		{
			throw new ObjectNotFoundException(
				"Value {$recordId} of dictionary {$type->value} is not readable right after a successful write."
			);
		}

		return self::toValue($record);
	}

	/**
	 * The refusal of the facade, whose text is the only thing it leaves behind: `Add()` answers `false`
	 * and `Update()` answers the record key whatever happened. The text is read right after the call and
	 * travels verbatim - it is what
	 * {@see \Bitrix\Crm\V2\Internal\Service\Dictionary\StorageRefusalMapper} recognizes a refusal by,
	 * and it is that mapper, not a caller, that ever looks at it.
	 */
	private static function refusalOf(\CCrmStatus $boundary): Result
	{
		return self::refusal([(string)$boundary->GetLastError()]);
	}

	/**
	 * Every refusal of a write of this boundary, and none of them has written anything: the facade turns a
	 * write down in its own checks, and the ORM collects the refusal of an `onBefore*` event and returns
	 * before the statement, while the `onAfter*` events of {@see StatusTable} raise no refusal at all. So a
	 * refusal carries the texts of the boundary and nothing besides - the domain above undoes nothing and
	 * has nothing to ask about the state of storage.
	 *
	 * @param string[] $messages texts of the boundary, handed up as it wrote them
	 */
	private static function refusal(array $messages): Result
	{
		$refusal = new Result();

		foreach ($messages as $message)
		{
			$refusal->addError(new Error((string)$message));
		}

		return $refusal;
	}

	private static function toValue(EO_Status $record): DictionaryValue
	{
		return new DictionaryValue(
			id: $record->getStatusId(),
			name: $record->getName(),
			sort: $record->getSort(),
			isSystem: $record->getSystem(),
		);
	}
}
