<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Result;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInBacklogService;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Command\CheckIn\Add;
use Bitrix\StaffTrack\Public\Command\CheckIn\AddBatch;

class CheckInService
{
	/**
	 * Authoritative upper bound on a single batch, re-exposed from {@see AddBatch::MAX_BATCH_SIZE}
	 * so transport consumers can reference the limit through this public facade instead of
	 * duplicating the value.
	 */
	public const MAX_BATCH_SIZE = AddBatch::MAX_BATCH_SIZE;

	/**
	 * Code the batch is refused with once it exceeds MAX_BATCH_SIZE, re-exposed from
	 * {@see AddBatch::ERROR_BATCH_SIZE}. A transport that cuts an oversized batch off before the command
	 * has to answer with this very code: the codes of the batch are a closed list the client tells a
	 * retriable outcome by, and an answer without one is repeated as a network failure.
	 */
	public const ERROR_BATCH_SIZE = AddBatch::ERROR_BATCH_SIZE;

	private static ?self $instance = null;

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function add(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		return (new Add())->execute($checkInDto, $uploadedFiles);
	}

	/**
	 * Batch write-path for accumulated auto check-in points submitted in a single call.
	 *
	 * @param CheckInDto[] $checkInDtos Pre-assembled DTOs (userId/timezone forced by the caller).
	 */
	public function addBatch(array $checkInDtos): Result
	{
		return (new AddBatch())->executeBatch($checkInDtos);
	}

	/**
	 * Folds the raw rows the acceptance window has left behind into the routes of their days, for as long
	 * as one run of the work is allowed to take. Returns true while the backlog still holds days the run
	 * did not reach, which is what the caller reschedules itself by.
	 *
	 * The fold itself is internal, and Infrastructure may not reach into that layer, so it is delegated
	 * from here and nothing else happens on the way.
	 */
	public function aggregateStaleCheckIns(): bool
	{
		return (new CheckInBacklogService())->aggregateStaleCheckIns();
	}
}
