<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Public\Command\Office\AbstractCommand;

/**
 * Runs inside the transaction of the caller and never ends it: a failure is rolled back by the caller.
 */
class StaffWriter
{
	/**
	 * A broken unique key of the staff: somebody was assigned elsewhere after the plan was read.
	 */
	public const CONFLICT = 'STAFFTRACK_OFFICE_EMPLOYEE_STAFF_CONFLICT';

	private OfficeEmployeeRepository $officeEmployeeRepository;

	public function __construct(?OfficeEmployeeRepository $officeEmployeeRepository = null)
	{
		$this->officeEmployeeRepository = $officeEmployeeRepository ?? new OfficeEmployeeRepository();
	}

	/**
	 * Movers lose rows by the exact pairs of the plan: an assignment made after the plan breaks the insert.
	 *
	 * @param int[] $removedUserIds
	 * @param array<int, int> $moves user id => the office they are taken from
	 * @param int[] $addedUserIds
	 */
	public function write(
		int $officeId,
		int $authorId,
		array $removedUserIds,
		array $moves,
		array $addedUserIds,
	): Result
	{
		$result = new Result();
		$chunkSize = OfficeEmployeeRepository::WRITE_CHUNK_SIZE;

		try
		{
			foreach (array_chunk($removedUserIds, $chunkSize) as $chunk)
			{
				$deleteResult = $this->officeEmployeeRepository->deleteByOfficeIdAndUserIds($officeId, $chunk);
				if (!$deleteResult->isSuccess())
				{
					return $result->addError($this->getFailure(implode('; ', $deleteResult->getErrorMessages())));
				}
			}

			foreach (array_chunk($moves, $chunkSize, true) as $pairs)
			{
				$deleteResult = $this->officeEmployeeRepository->deleteByPairs($pairs);
				if (!$deleteResult->isSuccess())
				{
					return $result->addError($this->getFailure(implode('; ', $deleteResult->getErrorMessages())));
				}
			}

			foreach (array_chunk($addedUserIds, $chunkSize) as $chunk)
			{
				$addResult = $this->officeEmployeeRepository->addMulti($officeId, $chunk, $authorId);
				if (!$addResult->isSuccess())
				{
					return $result->addError($this->getFailure(implode('; ', $addResult->getErrorMessages())));
				}
			}
		}
		catch (DuplicateEntryException $exception)
		{
			return $result->addError(new Error($this->describeThrowable($exception), self::CONFLICT));
		}
		catch (\Throwable $exception)
		{
			return $result->addError($this->getFailure($this->describeThrowable($exception)));
		}

		return $result;
	}

	private function getFailure(string $reason): Error
	{
		return new Error($reason, AbstractCommand::ERROR_SAVE_FAILED);
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a storage error carries the
	 * query it failed on, and that query carries the identifiers of the people.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
