<?php

namespace Bitrix\StaffTrack\Internal\Service\Office\AccessRequest;

use Bitrix\Main\Application;
use Bitrix\StaffTrack\Dictionary\OfficeAccessRequestStatus;
use Bitrix\StaffTrack\Internal\Integration\Im\OfficeAccessNotifier;
use Bitrix\StaffTrack\Internal\Repository\OfficeAccessRequestRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeChangeNotifier;

/**
 * Annuls the requests to see the staff of an office for the three reasons that take them away: the office
 * is removed, the requester is assigned to it, the requester is deactivated.
 *
 * What is written comes first and what people are told follows it: the deciders lose the confirmations of
 * an annulled request, and the requester is told why and pushed the office, except a deactivated one, who
 * is told nothing. A failure of the messenger or of the push channel is logged and takes nothing back.
 */
class AccessRequestCanceller
{
	private const CHUNK_SIZE = 500;

	private OfficeAccessRequestRepository $requestRepository;
	private OfficeAccessNotifier $notifier;
	private OfficeChangeNotifier $changeNotifier;
	private OfficeRepository $officeRepository;
	private OfficeEmployeeRepository $employeeRepository;

	public function __construct(
		?OfficeAccessRequestRepository $requestRepository = null,
		?OfficeAccessNotifier $notifier = null,
		?OfficeChangeNotifier $changeNotifier = null,
		?OfficeRepository $officeRepository = null,
		?OfficeEmployeeRepository $employeeRepository = null,
	)
	{
		$this->requestRepository = $requestRepository ?? new OfficeAccessRequestRepository();
		$this->notifier = $notifier ?? new OfficeAccessNotifier();
		$this->changeNotifier = $changeNotifier ?? new OfficeChangeNotifier();
		$this->officeRepository = $officeRepository ?? new OfficeRepository();
		$this->employeeRepository = $employeeRepository ?? new OfficeEmployeeRepository();
	}

	/**
	 * Removes every request and grant to an office and returns the requests that were pending. Runs inside
	 * the transaction of the removal of the office, and a failure of the storage leaves it to roll back.
	 *
	 * @param int $officeId
	 * @return array<array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int}>
	 */
	public function takeForDeletedOffice(int $officeId): array
	{
		return $this->requestRepository->takeByOfficeId($officeId);
	}

	/**
	 * Tells about the pending requests of an office once its removal is committed: the deciders lose the
	 * confirmations, and each requester is told the office is gone and pushed it.
	 *
	 * @param array<array{id: int, userId: int, revision: int}> $pending what {@see takeForDeletedOffice()} returned
	 * @param int $officeId
	 * @param string $officeTitle the title the office had before the removal
	 * @return void
	 */
	public function afterOfficeDeleted(array $pending, int $officeId, string $officeTitle): void
	{
		foreach ($pending as $request)
		{
			$this->announce($request, $officeId, $officeTitle, OfficeAccessNotifier::RESULT_OFFICE_DELETED);
		}
	}

	/**
	 * Annuls the pending requests of the people just assigned to an office: they see its staff anyway.
	 * Grants and refusals stay. Runs after the assignment is committed and never fails it: a failure is
	 * logged and stays here. The people are annulled and told a chunk at a time, so the requests annulled
	 * are never held all at once; a chunk is annulled in a transaction of its own, which a failure rolls back.
	 *
	 * @param int $officeId
	 * @param int[] $userIds the people the assignment has actually written
	 * @return void
	 */
	public function cancelForAssigned(int $officeId, array $userIds): void
	{
		$officeTitle = null;

		foreach (array_chunk($userIds, self::CHUNK_SIZE) as $chunk)
		{
			try
			{
				$cancelled = $this->changeOnMaster(
					fn (): array => $this->requestRepository->cancelPending($officeId, $chunk),
				);
			}
			catch (\Throwable $exception)
			{
				$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

				return;
			}

			if ($cancelled === [])
			{
				continue;
			}

			$officeTitle ??= $this->getOfficeTitle($officeId);

			foreach ($cancelled as $request)
			{
				$this->announce($request, $officeId, $officeTitle, OfficeAccessNotifier::RESULT_ASSIGNED);
			}
		}
	}

	/**
	 * Annuls the pending requests to an office of the people assigned to it by now, a page of the requests at
	 * a time: a mass assignment may name the whole company, while whoever came in is found from the side of
	 * the requests, which are few. Runs past the commit of the assignment and never fails it: a failure is
	 * logged and stays here.
	 *
	 * @param int $officeId
	 * @return void
	 */
	public function cancelForAssignedRequesters(int $officeId): void
	{
		try
		{
			foreach ($this->iterateAssignedRequesters($officeId) as $assignedIds)
			{
				$this->cancelForAssigned($officeId, $assignedIds);
			}
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}
	}

	/**
	 * Returns the people waiting for a decision on their request to an office who are assigned to it by now.
	 * A removed office hands its whole staff over in one statement that names nobody, so whoever came in is
	 * found from the side of the requests, which are few. Read inside the transaction of a transfer, where the
	 * moved rows are seen; the answer goes to {@see cancelForAssigned()} past the commit.
	 *
	 * @param int $officeId the office the staff was moved into
	 * @return int[]
	 */
	public function findAssignedRequesters(int $officeId): array
	{
		$assigned = [];

		foreach ($this->iterateAssignedRequesters($officeId) as $assignedIds)
		{
			array_push($assigned, ...$assignedIds);
		}

		return $assigned;
	}

	/**
	 * Removes every request and grant of a deactivated person, in a transaction of its own on the master, and
	 * drops the confirmations of the requests that were pending. The person is told nothing. A failure of the
	 * storage is rolled back and raised to the caller.
	 *
	 * @param int $userId
	 * @return void
	 */
	public function revokeForDeactivated(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$pending = $this->changeOnMaster(fn (): array => $this->requestRepository->takeByUserId($userId));

		foreach ($pending as $request)
		{
			$this->runQuietly(
				'dropRequest',
				fn () => $this->notifier->dropRequest($request['id'], $request['revision']),
			);
		}
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * Kept behind a method of its own, as in the commands: a test has a single connection and no replica.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	/**
	 * Runs a change of the requests that reads its rows FOR UPDATE, in a transaction of its own and on the
	 * master: the pool sends a read to a replica until the hit has written the table it reads, a transaction
	 * names no table, and on a replica the rows would be neither current nor locked. A failure of the storage
	 * is rolled back and raised.
	 *
	 * @param callable(): array $change
	 * @return array what the change returned
	 */
	private function changeOnMaster(callable $change): array
	{
		$this->useMasterOnly(true);

		try
		{
			$connection = Application::getConnection();
			$connection->startTransaction();

			try
			{
				$changed = $change();
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();

				throw $exception;
			}

			// A failed commit leaves as an exception: there is nothing to roll back after it.
			$connection->commitTransaction();
		}
		finally
		{
			$this->useMasterOnly(false);
		}

		return $changed;
	}

	/**
	 * @return \Generator<int, int[]> those of a page of the requesters who are assigned to the office
	 */
	private function iterateAssignedRequesters(int $officeId): \Generator
	{
		foreach ($this->requestRepository->iteratePendingUserIds($officeId) as $requesterIds)
		{
			$assigned = [];

			foreach ($this->employeeRepository->getOfficeIdsByUserIds($requesterIds) as $userId => $assignedOfficeId)
			{
				if ($assignedOfficeId === $officeId)
				{
					$assigned[] = $userId;
				}
			}

			if ($assigned !== [])
			{
				yield $assigned;
			}
		}
	}

	/**
	 * @param array{id: int, userId: int, revision: int} $request
	 * @param string $result one of the RESULT_* constants of {@see OfficeAccessNotifier}
	 */
	private function announce(array $request, int $officeId, string $officeTitle, string $result): void
	{
		$this->runQuietly(
			'dropRequest',
			fn () => $this->notifier->dropRequest($request['id'], $request['revision']),
		);
		$this->runQuietly(
			'sendResult',
			fn () => $this->notifier->sendResult($result, $request['userId'], $officeId, $officeTitle),
		);
		$this->runQuietly(
			'notifyAccessChanged',
			fn () => $this->changeNotifier->notifyAccessChanged($request['userId'], $officeId),
		);
	}

	/**
	 * The requests are annulled by now, so a title that cannot be read leaves them announced without it.
	 */
	private function getOfficeTitle(int $officeId): string
	{
		try
		{
			return (string)($this->officeRepository->getTitlesByIds([$officeId])[$officeId] ?? '');
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return '';
		}
	}

	private function runQuietly(string $operation, callable $announcement): void
	{
		try
		{
			$announcement();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($operation, $this->describeThrowable($exception));
		}
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a failure of the storage
	 * carries the query, and the query carries the people it was about.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
