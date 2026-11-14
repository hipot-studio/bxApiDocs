<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserUtils;
use Bitrix\StaffTrack\Internal\Model\OfficeEmployeeTable;
use Bitrix\StaffTrack\Internal\Model\OfficeTable;

/**
 * Storage of the assignments of employees to offices: one row per person, and the office of a person is
 * the row itself, which is why a single read answers both "who is already here" and "who is somewhere
 * else".
 *
 * A read of the staff and a count of it go through the record of the person and take the working ones
 * only; a write does not repeat that check, because who to write is settled by the resolving of the set
 * before the call.
 */
class OfficeEmployeeRepository
{
	/**
	 * The largest set one call to the storage carries. A property of the storage rather than a limit of
	 * the contract: the one a client is answered by lives with the command that answers it.
	 */
	public const WRITE_CHUNK_SIZE = 500;

	/**
	 * A page of the staff of an office, walked by a keyset over the identifier of the employee: the pair
	 * (office, employee) is the unique key, so the page is one seek rather than an offset the storage has
	 * to count up to.
	 *
	 * Whether the staff continues past the page is answered here and not above: one row over the page is
	 * read, left out of the answer and turned into the sign of it. A caller collects employees out of the
	 * identifiers and may end up with fewer of them than the rows read, so the same sign derived from the
	 * length of its own answer would call a page the last one while the staff goes on. The cursor is the
	 * last identifier read for the page, not the last one a caller kept.
	 *
	 * A search narrows the same walk by the filter of users the core builds, carried onto the person the
	 * row points at. A search the core finds no token in is an empty page rather than the whole staff.
	 *
	 * @return array{userIds: int[], nextAfterUserId: int, hasMore: bool}
	 */
	public function getUserIdsPage(int $officeId, int $afterUserId, int $limit, string $query = ''): array
	{
		$pageQuery = OfficeEmployeeTable::query()
			->setSelect(['USER_ID'])
			->where('OFFICE_ID', $officeId)
			->where('USER_ID', '>', $afterUserId)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit + 1)
		;

		if ($query !== '')
		{
			$searchFilter = $this->getUserSearchFilter($query);
			if (!$this->hasSearchToken($searchFilter))
			{
				return [
					'userIds' => [],
					'nextAfterUserId' => $afterUserId,
					'hasMore' => false,
				];
			}

			$pageQuery->setFilter($this->moveOntoUser($searchFilter));
		}

		$rows = $this->onlyWorkingEmployees($pageQuery)->fetchAll();

		$hasMore = count($rows) > $limit;
		$userIds = array_map(
			static fn (array $row): int => (int)$row['USER_ID'],
			array_slice($rows, 0, $limit),
		);

		return [
			'userIds' => $userIds,
			'nextAfterUserId' => $userIds === [] ? $afterUserId : end($userIds),
			'hasMore' => $hasMore,
		];
	}

	public function countByOfficeId(int $officeId): int
	{
		$query = OfficeEmployeeTable::query()
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->where('OFFICE_ID', $officeId)
		;

		$row = $this->onlyWorkingEmployees($query)->exec()->fetch();

		return (int)($row['CNT'] ?? 0);
	}

	/**
	 * The staff of several offices counted in one grouped query: the directory is read as a whole, and
	 * asking office by office would cost a query per office. An office without staff answers with a zero
	 * of its own rather than with a missing key.
	 *
	 * @param int[] $officeIds
	 * @return array<int, int> office id => how many employees it has
	 */
	public function countByOfficeIds(array $officeIds): array
	{
		if ($officeIds === [])
		{
			return [];
		}

		$counts = array_fill_keys(array_map('intval', $officeIds), 0);

		$query = OfficeEmployeeTable::query()
			->setSelect(['OFFICE_ID'])
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->whereIn('OFFICE_ID', $officeIds)
			->setGroup(['OFFICE_ID'])
		;

		foreach ($this->onlyWorkingEmployees($query)->fetchAll() as $row)
		{
			$counts[(int)$row['OFFICE_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * Where the people of a set are assigned right now. A person missing from the answer has no office at
	 * all; a person whose office is the one being assigned to is already there.
	 *
	 * @param int[] $userIds
	 * @return array<int, int> user id => the office they are assigned to
	 */
	public function getOfficeIdsByUserIds(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = OfficeEmployeeTable::query()
			->setSelect(['USER_ID', 'OFFICE_ID'])
			->whereIn('USER_ID', $userIds)
			->fetchAll()
		;

		$officeIds = [];

		foreach ($rows as $row)
		{
			$officeIds[(int)$row['USER_ID']] = (int)$row['OFFICE_ID'];
		}

		return $officeIds;
	}

	public function getWorkingOfficeIdByUserId(int $userId): ?int
	{
		$query = OfficeEmployeeTable::query()
			->setSelect(['OFFICE_ID'])
			->where('USER_ID', $userId)
			->setLimit(1)
		;

		$row = $this->onlyWorkingEmployees($query)->fetch();

		return $row === false ? null : (int)$row['OFFICE_ID'];
	}

	/**
	 * The office one person is assigned to, together with the zone around it, in one query: the row of
	 * the assignment carries the identifier alone, and asking the card of the office for the three
	 * numbers beside it would cost a second query on the hottest path of the module.
	 *
	 * The reference to the card is registered on the query rather than declared on the model: the model
	 * is shared with the reads of the staff above, and a field added there would reach all of them.
	 *
	 * The join is inner on purpose - an assignment to an office already gone answers with nothing, the
	 * same as no assignment at all, so no branch of its own is needed for it. Whether the person is still
	 * working is not asked either: this read stands behind a point of their own being written, the same
	 * as {@see self::getOfficeIdsByUserIds()}.
	 *
	 * @return array{id: int, latitude: float, longitude: float, geoRadius: int}|null
	 */
	public function getOfficeZoneByUserId(int $userId): ?array
	{
		$row = OfficeEmployeeTable::query()
			->registerRuntimeField(
				'OFFICE',
				new Reference(
					'OFFICE',
					OfficeTable::class,
					Join::on('this.OFFICE_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER],
				),
			)
			->setSelect([
				'OFFICE_ID',
				'LATITUDE' => 'OFFICE.LATITUDE',
				'LONGITUDE' => 'OFFICE.LONGITUDE',
				'GEO_RADIUS' => 'OFFICE.GEO_RADIUS',
			])
			->where('USER_ID', $userId)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return [
			'id' => (int)$row['OFFICE_ID'],
			'latitude' => (float)$row['LATITUDE'],
			'longitude' => (float)$row['LONGITUDE'],
			'geoRadius' => (int)$row['GEO_RADIUS'],
		];
	}

	/**
	 * The rows of a set in one statement. The size of the set is the business of the caller and not of
	 * this method: a chunk that failed is written again person by person, and that pass needs this very
	 * method rather than a second one. A single row is delegated to an ordinary add by the core, so a
	 * branch for one person is not needed here either.
	 *
	 * Events are ignored on purpose: the table has no handlers, and without this the batch would walk
	 * onBeforeAdd row by row and lose its point.
	 *
	 * A broken unique key arrives as an SQL exception rather than as an error of the result, so a person
	 * repeated inside one call takes the whole call back with them. The caller keeps the set free of
	 * repetitions and holds the transaction this is written in.
	 *
	 * @param int[] $userIds
	 */
	public function addMulti(int $officeId, array $userIds, int $createdBy): AddResult
	{
		if ($userIds === [])
		{
			return new AddResult();
		}

		$rows = [];

		foreach ($userIds as $userId)
		{
			$rows[] = [
				'OFFICE_ID' => $officeId,
				'USER_ID' => (int)$userId,
				'CREATED_BY' => $createdBy,
			];
		}

		return OfficeEmployeeTable::addMulti($rows, true);
	}

	/**
	 * The rows of the named people wherever they are: this is how an assignment takes an employee out of
	 * the office they belonged to before. Removing them from one named office is a different question and
	 * is asked by {@see self::deleteByOfficeIdAndUserIds()}.
	 *
	 * @param int[] $userIds
	 */
	public function deleteByUserIds(array $userIds): Result
	{
		if ($userIds === [])
		{
			return new Result();
		}

		return $this->deleteByFilter(['@USER_ID' => array_map('intval', $userIds)]);
	}

	/**
	 * The rows of the named people in one office. A person of the set who was never there brings no row
	 * and no failure with them.
	 *
	 * @param int[] $userIds
	 */
	public function deleteByOfficeIdAndUserIds(int $officeId, array $userIds): Result
	{
		if ($userIds === [])
		{
			return new Result();
		}

		return $this->deleteByFilter([
			'=OFFICE_ID' => $officeId,
			'@USER_ID' => array_map('intval', $userIds),
		]);
	}

	/**
	 * The rows of exact pairs in one statement, whatever number of offices the people come from. A person
	 * whose row is in another office than the pair names keeps it: the condition on the office holds for
	 * every pair, and only the outer set of people keeps the statement on the unique key of a person.
	 *
	 * @param array<int, int> $moves user id => the office their row is taken from; at most WRITE_CHUNK_SIZE
	 * @throws ArgumentException when the chunk is larger than one statement carries
	 */
	public function deleteByPairs(array $moves): Result
	{
		if ($moves === [])
		{
			return new Result();
		}

		if (count($moves) > self::WRITE_CHUNK_SIZE)
		{
			throw new ArgumentException('At most ' . self::WRITE_CHUNK_SIZE . ' pairs per call', 'moves');
		}

		$userIdsByOffice = [];

		foreach ($moves as $userId => $officeId)
		{
			$userIdsByOffice[(int)$officeId][] = (int)$userId;
		}

		$byOffice = ['LOGIC' => 'OR'];

		foreach ($userIdsByOffice as $officeId => $userIds)
		{
			$byOffice[] = [
				'=OFFICE_ID' => $officeId,
				'@USER_ID' => $userIds,
			];
		}

		return $this->deleteByFilter([
			'@USER_ID' => array_map('intval', array_keys($moves)),
			$byOffice,
		]);
	}

	/**
	 * The whole staff of an office at once: an office leaving the directory takes its assignments with it.
	 */
	public function deleteByOfficeId(int $officeId): Result
	{
		return $this->deleteByFilter(['=OFFICE_ID' => $officeId]);
	}

	/**
	 * The whole staff of an office handed over to another one in a single statement: every row it has,
	 * the dismissed and the service records included, so nobody is read and a staff of any size costs the
	 * same one statement. A moved row is a new assignment - the one who moved it and the moment of the
	 * move replace the author and the date it had.
	 *
	 * Neither unique key can break: a person keeps the one row they had, and no other office holds a row
	 * of theirs. A failure comes back as an error for the reason and in the shape of
	 * {@see self::deleteByFilter()}; the caller holds the transaction.
	 */
	public function moveAllToOffice(int $fromOfficeId, int $toOfficeId, int $movedBy): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		try
		{
			$connection->queryExecute(
				'UPDATE ' . $sqlHelper->quote(OfficeEmployeeTable::getTableName())
				. ' SET ' . $sqlHelper->quote('OFFICE_ID') . ' = ' . $toOfficeId
				. ', ' . $sqlHelper->quote('CREATED_BY') . ' = ' . $movedBy
				. ', ' . $sqlHelper->quote('DATE_CREATE') . ' = ' . $sqlHelper->convertToDbDateTime(new DateTime())
				. ' WHERE ' . $sqlHelper->quote('OFFICE_ID') . ' = ' . $fromOfficeId,
			);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error(
				$exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine(),
			));
		}

		return $result;
	}

	/**
	 * A mass delete answers with nothing at all and reports a failure by raising, so the exception is
	 * carried back as an error: the caller removes these rows inside a transaction and has to be able to
	 * take the rest of it back with them.
	 *
	 * The message of the exception stays out of the error: a storage failure carries the query it failed
	 * on, and that query carries the identifiers of the people.
	 */
	private function deleteByFilter(array $filter): Result
	{
		$result = new Result();

		try
		{
			OfficeEmployeeTable::deleteByFilter($filter);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Error(
				$exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine(),
			));
		}

		return $result;
	}

	/**
	 * The filter of users the core builds for a search: a full-text one where the portal keeps the index
	 * of names, a fallback one of nested conditions where it does not.
	 */
	protected function getUserSearchFilter(string $query): array
	{
		return UserUtils::getUserSearchFilter(['FIND' => $query]);
	}

	/**
	 * A full-text condition without a token is dropped from the query silently, and the page would then
	 * be the whole staff instead of nobody.
	 */
	private function hasSearchToken(array $filter): bool
	{
		if ($filter === [])
		{
			return false;
		}

		foreach ($filter as $key => $condition)
		{
			if (is_int($key))
			{
				if (is_array($condition) && !$this->hasSearchToken($condition))
				{
					return false;
				}

				continue;
			}

			[$operator] = $this->splitCondition($key);
			$isFullText = $operator === '*' || $operator === '*=';

			if ($isFullText && Filter\Helper::parseFulltextPhrase((string)$condition) === [])
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The filter of the core names the fields of a user, and the staff is read from the rows of
	 * assignment, so every field is reached through the person the row points at.
	 */
	private function moveOntoUser(array $filter): array
	{
		$moved = [];

		foreach ($filter as $key => $condition)
		{
			if (is_int($key))
			{
				$moved[$key] = is_array($condition) ? $this->moveOntoUser($condition) : $condition;
			}
			elseif ($key === 'LOGIC')
			{
				$moved[$key] = $condition;
			}
			else
			{
				[$operator, $field] = $this->splitCondition($key);
				$moved[$operator . 'USER.' . $field] = $condition;
			}
		}

		return $moved;
	}

	/**
	 * @return array{0: string, 1: string} the operator of a condition and the field it is put on
	 */
	private function splitCondition(string $key): array
	{
		preg_match('/^(\W*)(.*)$/s', $key, $parts);

		return [$parts[1], $parts[2]];
	}

	/**
	 * The working staff of the company and nobody else: a dismissed account and a service record both
	 * keep their row and both stay out of every read. No reading query may skip this - a count taken
	 * without it and a page taken with it would disagree on everyone who has left.
	 */
	private function onlyWorkingEmployees(Query $query): Query
	{
		return $query
			->where('USER.ACTIVE', 'Y')
			->where('USER.REAL_USER', 'expr', true)
		;
	}
}
