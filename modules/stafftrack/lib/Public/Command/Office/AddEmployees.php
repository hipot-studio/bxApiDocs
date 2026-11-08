<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\SelectorEntityNormalizer;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\EmployeeSetResolver;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * Puts a set of people, named by the codes of the entity selector, on the staff of an office. An
 * employee belongs to one office, so assigning somebody who is on the staff of another one moves them
 * rather than refuses: the set says where these people are now, not where they have never been.
 *
 * The set is streamed in chunks and written chunk by chunk, so the call is not atomic and does not
 * pretend to be. A refusal of the whole call is only possible before the first row is written, and the
 * grounds for it are five: no right, too many codes, no structure to take every employee from, no
 * office, and an office held by another operation. Past that point everything is a report: how many
 * were assigned, which offices they were taken from and who was not accepted.
 */
class AddEmployees extends AbstractCommand
{
	public const ERROR_BUSY = OfficeErrorCode::BUSY;
	public const ERROR_TOO_MANY_CODES = OfficeEmployeeErrorCode::TOO_MANY_CODES;

	/**
	 * How many refusals in a row end the person by person pass over a chunk the batch refused. A batch
	 * goes back over one impassable row, and it goes back the same way over something that will refuse
	 * everybody - an unreachable connection, a storage with no room left, a table taken away. The second
	 * is told from the first by repetition rather than by the code of the storage, which differs between
	 * the engines, and the count is of refusals in a row: a success resets it.
	 */
	public const SINGLE_PASS_FAILURE_LIMIT = 3;

	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected EmployeeSetResolver $employeeSetResolver;

	protected function init(): void
	{
		parent::init();

		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->employeeSetResolver = new EmployeeSetResolver();
	}

	/**
	 * @param array $entities codes of the entity selector: a person, a department, every employee
	 * @return Result Data of a success: {
	 *     assigned: int,
	 *     moved: array<int, array{fromOfficeId: int, count: int}>,
	 *     rejected: array<int, array{userId: int, code: string}>,
	 * }
	 */
	public function execute(OfficeDto $officeDto, array $entities = []): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::UPDATE, $officeDto->id))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validatePrimary();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		// The raw codes are counted before the parse, which truncates past the same limit without a word:
		// counted after it, an excess would come back as a success with the lost codes never mentioned.
		if (count($entities) > SelectorEntityNormalizer::MAX_SELECTOR_ENTITIES)
		{
			return $result->addError($this->getTooManyCodesError());
		}

		$setResult = $this->employeeSetResolver->resolve($entities);
		if (!$setResult->isSuccess())
		{
			return $result->addErrors($setResult->getErrors());
		}

		$set = $setResult->getData();

		return $this->assign($officeDto->id, $set['employees'], $set['rejected']);
	}

	/**
	 * The lock is taken once for the whole call and only on the office being assigned to: the offices
	 * people are taken from are never locked, so a call never holds more than one of them and two mass
	 * assignments in opposite directions cannot wedge on them.
	 *
	 * The office is looked up once, under the lock, and is not looked up again: the removal of an office
	 * takes this very lock, so it cannot happen while the set is being written.
	 *
	 * @param \Generator<int, int[]> $employees
	 * @param array<int, array{userId: int, code: string}> $rejected
	 */
	private function assign(int $officeId, \Generator $employees, array $rejected): Result
	{
		$result = new Result();

		if (!$this->officeRepository->lock($officeId))
		{
			$this->logFailure(__FUNCTION__, 'office ' . $officeId . ' is held by another operation');

			return $result->addError($this->getBusyError());
		}

		try
		{
			if ($this->officeRepository->getById($officeId) === null)
			{
				return $result->addError($this->getNotFoundError());
			}

			return $result->setData($this->writeSet($officeId, $employees, $rejected));
		}
		finally
		{
			$this->officeRepository->unlock($officeId);
		}
	}

	/**
	 * The set walked chunk by chunk, never held whole: the summary of the moves grows by a counter per
	 * office touched rather than by the people behind it, which is what keeps the assignment of a whole
	 * company out of memory.
	 *
	 * @param \Generator<int, int[]> $employees
	 * @param array<int, array{userId: int, code: string}> $rejected
	 * @return array{
	 *     assigned: int,
	 *     moved: array<int, array{fromOfficeId: int, count: int}>,
	 *     rejected: array<int, array{userId: int, code: string}>,
	 * }
	 */
	private function writeSet(int $officeId, \Generator $employees, array $rejected): array
	{
		$assigned = 0;
		$movedByOffice = [];

		foreach ($employees as $chunk)
		{
			$report = $this->writeChunk($officeId, $chunk);

			$assigned += $report['assigned'];
			$rejected = array_merge($rejected, $report['rejected']);

			foreach ($report['movedByOffice'] as $fromOfficeId => $count)
			{
				$movedByOffice[$fromOfficeId] = ($movedByOffice[$fromOfficeId] ?? 0) + $count;
			}
		}

		return [
			'assigned' => $assigned,
			'moved' => $this->buildMoved($movedByOffice),
			'rejected' => array_values($rejected),
		];
	}

	/**
	 * One chunk of the set. The people are split by the office they belong to right now, and the split is
	 * kept in maps rather than in lists: the row of a person is looked up by them anyway, and a person
	 * repeated inside one chunk collapses here for free - a repetition reaching the batch would take the
	 * whole chunk back with it.
	 *
	 * @param int[] $chunk
	 * @return array{
	 *     assigned: int,
	 *     movedByOffice: array<int, int>,
	 *     rejected: array<int, array{userId: int, code: string}>,
	 * }
	 */
	private function writeChunk(int $officeId, array $chunk): array
	{
		$officeIdsByUser = $this->officeEmployeeRepository->getOfficeIdsByUserIds($chunk);

		/** @var array<int, int> $movers user id => the office they are taken from */
		$movers = [];
		/** @var array<int, true> $fresh user id => belongs to no office at all */
		$fresh = [];

		foreach ($chunk as $userId)
		{
			$userId = (int)$userId;
			$currentOfficeId = (int)($officeIdsByUser[$userId] ?? 0);

			// Already on the staff of this office: no removal, no insertion, no line of the report.
			if ($currentOfficeId === $officeId)
			{
				continue;
			}

			if ($currentOfficeId > 0)
			{
				$movers[$userId] = $currentOfficeId;
			}
			else
			{
				$fresh[$userId] = true;
			}
		}

		$userIds = [...array_keys($movers), ...array_keys($fresh)];
		if ($userIds === [])
		{
			return ['assigned' => 0, 'movedByOffice' => [], 'rejected' => []];
		}

		if ($this->writeRows($officeId, array_keys($movers), $userIds))
		{
			return [
				'assigned' => count($userIds),
				'movedByOffice' => array_count_values($movers),
				'rejected' => [],
			];
		}

		return $this->writeOneByOne($officeId, $movers, $userIds);
	}

	/**
	 * The chunk written again person by person, after the batch went back whole. The batch is all or
	 * nothing and one row sinks it: somebody read as belonging nowhere was assigned elsewhere before the
	 * insert, or a facing call deadlocked on the same rows. Losing the whole chunk over one person would
	 * be the wrong answer, so the reason is not asked for - it is not readable across the engines anyway -
	 * and the rejected are the people who really did not pass.
	 *
	 * The pass is bounded by {@see self::SINGLE_PASS_FAILURE_LIMIT}: what refuses everybody would
	 * otherwise be asked five hundred times for an answer already known, and the rest of the chunk goes
	 * to the rejected with the same reason.
	 *
	 * @param array<int, int> $movers user id => the office they are taken from
	 * @param int[] $userIds
	 * @return array{
	 *     assigned: int,
	 *     movedByOffice: array<int, int>,
	 *     rejected: array<int, array{userId: int, code: string}>,
	 * }
	 */
	private function writeOneByOne(int $officeId, array $movers, array $userIds): array
	{
		$assigned = 0;
		$movedByOffice = [];
		$rejected = [];
		$failuresInARow = 0;

		foreach ($userIds as $position => $userId)
		{
			if ($failuresInARow >= self::SINGLE_PASS_FAILURE_LIMIT)
			{
				foreach (array_slice($userIds, $position) as $restUserId)
				{
					$rejected[] = $this->rejectSaveFailed($restUserId);
				}

				break;
			}

			$fromOfficeId = $movers[$userId] ?? 0;
			$moverIds = $fromOfficeId > 0 ? [$userId] : [];

			if (!$this->writeRows($officeId, $moverIds, [$userId]))
			{
				$failuresInARow++;
				$rejected[] = $this->rejectSaveFailed($userId);

				continue;
			}

			$failuresInARow = 0;
			$assigned++;

			if ($fromOfficeId > 0)
			{
				$movedByOffice[$fromOfficeId] = ($movedByOffice[$fromOfficeId] ?? 0) + 1;
			}
		}

		return ['assigned' => $assigned, 'movedByOffice' => $movedByOffice, 'rejected' => $rejected];
	}

	/**
	 * The one transaction of the write: the previous rows of the people being moved go first and the new
	 * rows follow, so nobody is left with no office at all by a move done half way.
	 *
	 * @param int[] $moverIds the people whose previous row has to go
	 * @param int[] $userIds everybody a row is written for
	 */
	private function writeRows(int $officeId, array $moverIds, array $userIds): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleteResult = $this->officeEmployeeRepository->deleteByUserIds($moverIds);
			if (!$deleteResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $deleteResult->getErrorMessages()));

				return false;
			}

			$addResult = $this->officeEmployeeRepository->addMulti($officeId, $userIds, $this->userId);
			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $addResult->getErrorMessages()));

				return false;
			}

			$connection->commitTransaction();

			return true;
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return false;
		}
	}

	/**
	 * The moves as a client is told about them: a line per office people were taken from, and never the
	 * people themselves - moving a whole company would make that list as long as the company is.
	 *
	 * @param array<int, int> $movedByOffice
	 * @return array<int, array{fromOfficeId: int, count: int}>
	 */
	private function buildMoved(array $movedByOffice): array
	{
		$moved = [];

		foreach ($movedByOffice as $fromOfficeId => $count)
		{
			$moved[] = [
				'fromOfficeId' => (int)$fromOfficeId,
				'count' => $count,
			];
		}

		return $moved;
	}

	/**
	 * @return array{userId: int, code: string}
	 */
	private function rejectSaveFailed(int $userId): array
	{
		return [
			'userId' => $userId,
			'code' => OfficeEmployeeErrorCode::SAVE_FAILED,
		];
	}

	private function getBusyError(): Error
	{
		return new Error('The office is held by another operation', self::ERROR_BUSY);
	}

	private function getTooManyCodesError(): Error
	{
		return new Error('Too many codes of the selector', self::ERROR_TOO_MANY_CODES);
	}
}
