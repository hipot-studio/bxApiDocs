<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Dictionary\OfficeAccessRequestStatus;
use Bitrix\StaffTrack\Internal\Model\OfficeAccessRequestTable;

/**
 * Storage of the requests to see the staff of an office: one row per pair of an office and a requester,
 * holding the current request and, once approved, the granted access.
 *
 * Every change of a status is a conditional transition - one UPDATE naming the row, its revision and the
 * status the caller saw - and wins only when it meets exactly that row. A lost race comes back as an
 * answer rather than as an exception, and nothing is locked around a transition. The annulment of many
 * requests at once is the one exception: it locks the rows it annuls (see {@see cancelPending()}).
 *
 * A request is answered as array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus,
 * revision: int}.
 */
class OfficeAccessRequestRepository
{
	private const COLUMNS = ['ID', 'OFFICE_ID', 'USER_ID', 'STATUS', 'REVISION'];
	private const FIRST_REVISION = 1;
	private const CHUNK_SIZE = 500;

	/**
	 * Returns the request of a person to an office, or null when there is none.
	 *
	 * @param int $officeId
	 * @param int $userId
	 * @return array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}|null
	 */
	public function find(int $officeId, int $userId): ?array
	{
		$row = OfficeAccessRequestTable::query()
			->setSelect(self::COLUMNS)
			->where('USER_ID', $userId)
			->where('OFFICE_ID', $officeId)
			->fetch()
		;

		return $row === false ? null : $this->toRequest($row);
	}

	/**
	 * Returns the request with the given identifier, or null when there is none.
	 *
	 * @param int $id
	 * @return array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}|null
	 */
	public function findById(int $id): ?array
	{
		$row = OfficeAccessRequestTable::query()
			->setSelect(self::COLUMNS)
			->where('ID', $id)
			->fetch()
		;

		return $row === false ? null : $this->toRequest($row);
	}

	/**
	 * Returns whether the request still waits for a decision under the given revision: a decision, a request
	 * asked again, an annulment and the removal of the row all end the wait of the revision.
	 *
	 * @param int $id
	 * @param int $revision
	 * @return bool
	 */
	public function isPending(int $id, int $revision): bool
	{
		$request = $this->findById($id);

		return $request !== null
			&& $request['status'] === OfficeAccessRequestStatus::PENDING
			&& $request['revision'] === $revision
		;
	}

	/**
	 * Returns those of the given offices whose staff the person was granted access to.
	 *
	 * @param int $userId
	 * @param int[] $officeIds
	 * @return int[] ascending
	 */
	public function getGrantedOfficeIds(int $userId, array $officeIds): array
	{
		$granted = [];

		foreach (array_chunk($this->normalizeIds($officeIds), self::CHUNK_SIZE) as $chunk)
		{
			$rows = OfficeAccessRequestTable::query()
				->setSelect(['OFFICE_ID'])
				->where('USER_ID', $userId)
				->whereIn('OFFICE_ID', $chunk)
				->where('STATUS', OfficeAccessRequestStatus::APPROVED->value)
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$granted[] = (int)$row['OFFICE_ID'];
			}
		}

		sort($granted);

		return $granted;
	}

	/**
	 * Walks the people waiting for a decision on their request to an office a page at a time, so no more
	 * than a page is read at once however many people asked. The pages follow the identifiers of the rows:
	 * a request annulled or decided between two pages neither comes back nor hides the ones after it.
	 *
	 * @param int $officeId
	 * @return \Generator<int, int[]> the requesters of one page, in the order the requests were opened
	 */
	public function iteratePendingUserIds(int $officeId): \Generator
	{
		$pageSize = $this->getPageSize();
		$lastId = 0;

		do
		{
			$rows = OfficeAccessRequestTable::query()
				->setSelect(['ID', 'USER_ID'])
				->where('OFFICE_ID', $officeId)
				->where('STATUS', OfficeAccessRequestStatus::PENDING->value)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($pageSize)
				->fetchAll()
			;

			if ($rows === [])
			{
				return;
			}

			$lastId = (int)$rows[array_key_last($rows)]['ID'];

			yield array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
		}
		while (count($rows) === $pageSize);
	}

	/**
	 * Opens a request: inserts a pending one, or moves a refused or annulled one back to pending under the
	 * next revision. Returns null when the pair already has a pending request or a grant, and when a
	 * parallel request opened it first.
	 *
	 * @param int $officeId
	 * @param int $userId
	 * @return array{
	 *     id: int,
	 *     officeId: int,
	 *     userId: int,
	 *     status: OfficeAccessRequestStatus,
	 *     revision: int,
	 *     previousStatus: ?OfficeAccessRequestStatus,
	 * }|null previousStatus is null for a request that had no row before
	 */
	public function open(int $officeId, int $userId): ?array
	{
		$request = $this->find($officeId, $userId);

		if ($request === null)
		{
			return $this->insertPending($officeId, $userId);
		}

		if (
			$request['status'] !== OfficeAccessRequestStatus::REJECTED
			&& $request['status'] !== OfficeAccessRequestStatus::CANCELLED
		)
		{
			return null;
		}

		$revision = $request['revision'] + 1;
		$isReopened = $this->transit(
			$request['id'],
			$request['revision'],
			$request['status'],
			[
				'STATUS' => OfficeAccessRequestStatus::PENDING->value,
				'REVISION' => $revision,
				'REQUESTED_AT' => new DateTime(),
			],
		);

		if (!$isReopened)
		{
			return null;
		}

		return [
			...$request,
			'status' => OfficeAccessRequestStatus::PENDING,
			'revision' => $revision,
			'previousStatus' => $request['status'],
		];
	}

	/**
	 * Takes back a request {@see open()} opened: a first one is removed, a repeated one gets its previous
	 * status back. A request decided or reopened meanwhile is left as it is.
	 *
	 * @param array{id: int, revision: int, previousStatus: ?OfficeAccessRequestStatus} $opened
	 * @return void
	 */
	public function restore(array $opened): void
	{
		$filter = $this->transitionFilter(
			(int)$opened['id'],
			(int)$opened['revision'],
			OfficeAccessRequestStatus::PENDING,
		);

		$previousStatus = $opened['previousStatus'];

		if ($previousStatus === null)
		{
			OfficeAccessRequestTable::deleteByFilter($filter);

			return;
		}

		$this->update(['STATUS' => $previousStatus->value], $filter);
	}

	/**
	 * Decides a pending request of the given revision. Returns false when the request is no longer pending
	 * under that revision: somebody decided it first, or it was asked again since.
	 *
	 * @param int $id
	 * @param int $revision
	 * @param bool $approve
	 * @param int $deciderId
	 * @return bool
	 */
	public function decide(int $id, int $revision, bool $approve, int $deciderId): bool
	{
		$status = $approve ? OfficeAccessRequestStatus::APPROVED : OfficeAccessRequestStatus::REJECTED;

		return $this->transit(
			$id,
			$revision,
			OfficeAccessRequestStatus::PENDING,
			[
				'STATUS' => $status->value,
				'DECIDED_BY' => $deciderId,
				'DECIDED_AT' => new DateTime(),
			],
		);
	}

	/**
	 * Annuls a pending request of the given revision. Returns false when the request is no longer pending
	 * under that revision: somebody decided it first, or it was asked again since.
	 *
	 * @param int $id
	 * @param int $revision
	 * @return bool
	 */
	public function cancel(int $id, int $revision): bool
	{
		return $this->transit(
			$id,
			$revision,
			OfficeAccessRequestStatus::PENDING,
			['STATUS' => OfficeAccessRequestStatus::CANCELLED->value],
		);
	}

	/**
	 * Annuls the pending requests of the given people to an office. Grants and refusals stay. Meant to run
	 * inside the transaction of the caller: the pending rows of a chunk of the people are read FOR UPDATE and
	 * annulled by one statement, so a decision taken meanwhile waits for the caller and then finds the request
	 * no longer pending, and every request read is one the statement annulled.
	 *
	 * @param int $officeId
	 * @param int[] $userIds
	 * @return array<array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}>
	 *     the requests this call annulled
	 */
	public function cancelPending(int $officeId, array $userIds): array
	{
		$connection = Application::getConnection();
		$cancelled = [];

		foreach (array_chunk($this->normalizeIds($userIds), self::CHUNK_SIZE) as $chunk)
		{
			$query = OfficeAccessRequestTable::query()
				->setSelect(self::COLUMNS)
				->where('OFFICE_ID', $officeId)
				->where('STATUS', OfficeAccessRequestStatus::PENDING->value)
				->whereIn('USER_ID', $chunk)
				->setOrder(['ID' => 'ASC'])
			;

			$rows = $connection->query($query->getQuery() . ' FOR UPDATE')->fetchAll();
			if ($rows === [])
			{
				continue;
			}

			$this->update(
				['STATUS' => OfficeAccessRequestStatus::CANCELLED->value],
				[
					'@ID' => array_map(static fn(array $row): int => (int)$row['ID'], $rows),
					'=STATUS' => OfficeAccessRequestStatus::PENDING->value,
				],
			);

			foreach ($rows as $row)
			{
				$cancelled[] = [...$this->toRequest($row), 'status' => OfficeAccessRequestStatus::CANCELLED];
			}
		}

		return $cancelled;
	}

	/**
	 * Removes every request to an office and returns the ones that were pending. Meant to run inside the
	 * transaction of the caller, which the removal belongs to.
	 *
	 * @param int $officeId
	 * @return array<array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}>
	 */
	public function takeByOfficeId(int $officeId): array
	{
		return $this->take('OFFICE_ID', $officeId);
	}

	/**
	 * Removes every request and grant of a person and returns the requests that were pending. Meant to run
	 * inside the transaction of the caller, which the removal belongs to.
	 *
	 * @param int $userId
	 * @return array<array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}>
	 */
	public function takeByUserId(int $userId): array
	{
		return $this->take('USER_ID', $userId);
	}

	private function insertPending(int $officeId, int $userId): ?array
	{
		try
		{
			$result = OfficeAccessRequestTable::add([
				'OFFICE_ID' => $officeId,
				'USER_ID' => $userId,
				'STATUS' => OfficeAccessRequestStatus::PENDING->value,
				'REVISION' => self::FIRST_REVISION,
				'REQUESTED_AT' => new DateTime(),
			]);
		}
		catch (DuplicateEntryException)
		{
			// a parallel request of the same pair inserted its row between the read and this insert
			return null;
		}

		if (!$result->isSuccess())
		{
			return null;
		}

		return [
			'id' => (int)$result->getId(),
			'officeId' => $officeId,
			'userId' => $userId,
			'status' => OfficeAccessRequestStatus::PENDING,
			'revision' => self::FIRST_REVISION,
			'previousStatus' => null,
		];
	}

	/**
	 * How many rows a page of a walk over the requests holds.
	 */
	protected function getPageSize(): int
	{
		return self::CHUNK_SIZE;
	}

	/**
	 * Only the pending rows are read, FOR UPDATE: a decision taken between this read and the removal waits
	 * for the transaction of the caller instead of being reported as a pending request. They are read a page
	 * at a time, so the storage never hands the whole set over in one piece; what comes back is what their
	 * requesters and deciders are told past the commit. The grants and the refusals leave with the removal
	 * unread - nobody decides on them any more, and an office may hold one of them for every person of the
	 * portal.
	 */
	private function take(string $column, int $value): array
	{
		$connection = Application::getConnection();
		$pageSize = $this->getPageSize();
		$taken = [];
		$lastId = 0;

		do
		{
			$query = OfficeAccessRequestTable::query()
				->setSelect(self::COLUMNS)
				->where($column, $value)
				->where('STATUS', OfficeAccessRequestStatus::PENDING->value)
				->where('ID', '>', $lastId)
				->setOrder(['ID' => 'ASC'])
				->setLimit($pageSize)
			;

			$rows = $connection->query($query->getQuery() . ' FOR UPDATE')->fetchAll();

			foreach ($rows as $row)
			{
				$taken[] = $this->toRequest($row);
				$lastId = (int)$row['ID'];
			}
		}
		while (count($rows) === $pageSize);

		OfficeAccessRequestTable::deleteByFilter(['=' . $column => $value]);

		return $taken;
	}

	private function transit(int $id, int $revision, OfficeAccessRequestStatus $from, array $fields): bool
	{
		return $this->update($fields, $this->transitionFilter($id, $revision, $from)) === 1;
	}

	private function transitionFilter(int $id, int $revision, OfficeAccessRequestStatus $status): array
	{
		return [
			'=ID' => $id,
			'=REVISION' => $revision,
			'=STATUS' => $status->value,
		];
	}

	/**
	 * @return int the number of rows the statement changed
	 */
	private function update(array $fields, array $filter): int
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = OfficeAccessRequestTable::getTableName();

		[$assignments] = $sqlHelper->prepareUpdate($tableName, $fields);
		$where = Query::buildFilterSql(OfficeAccessRequestTable::getEntity(), $filter);

		$connection->queryExecute(
			'UPDATE ' . $sqlHelper->quote($tableName) . ' SET ' . $assignments . ' WHERE ' . $where,
		);

		return $connection->getAffectedRowsCount();
	}

	/**
	 * @return array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}
	 */
	private function toRequest(array $row): array
	{
		return [
			'id' => (int)$row['ID'],
			'officeId' => (int)$row['OFFICE_ID'],
			'userId' => (int)$row['USER_ID'],
			'status' => OfficeAccessRequestStatus::from((string)$row['STATUS']),
			'revision' => (int)$row['REVISION'],
		];
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private function normalizeIds(array $ids): array
	{
		$ids = array_map('intval', $ids);

		return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
	}
}
