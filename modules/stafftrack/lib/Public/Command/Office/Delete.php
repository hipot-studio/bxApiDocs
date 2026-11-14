<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Model\OfficePhoto;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficePhotoRepository;
use Bitrix\StaffTrack\Internal\Service\Office\AccessRequest\AccessRequestCanceller;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeSnapshotService;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Provider\OfficesCountProvider;

/**
 * Removes an office for good: a closed office is deleted rather than hidden, and the check-ins its
 * employees left behind are not touched - they never pointed at the office. Everything that hangs off
 * the card goes in the same transaction as the card itself, and the rows of the neighbouring tasks of
 * the directory are to be removed here as well.
 *
 * The staff either leaves with the office or, when an office of transfer is named, moves there whole in
 * that same transaction: there is no state in which the office is gone and only a part of its people
 * found a new one. A successful result carries
 * `['id' => int, 'targetOfficeId' => ?int, 'movedCount' => int, 'fileIds' => int[]]`, where the count is
 * of the working employees that moved and is zero without a transfer.
 */
class Delete extends AbstractCommand
{
	protected OfficePhotoRepository $officePhotoRepository;
	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected OfficeSnapshotService $officeSnapshotService;
	protected AccessRequestCanceller $accessRequestCanceller;

	protected function init(): void
	{
		parent::init();

		$this->officePhotoRepository = new OfficePhotoRepository();
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->officeSnapshotService = new OfficeSnapshotService();
		$this->accessRequestCanceller = new AccessRequestCanceller();
	}

	public function execute(OfficeDto $officeDto): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::DELETE, $officeDto->id))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validateDelete();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		$targetOfficeId = $officeDto->targetOfficeId > 0 ? $officeDto->targetOfficeId : null;

		return $this->remove($officeDto->id, $targetOfficeId);
	}

	/**
	 * The card is removed while holding the lock of the office, and the addition of photos takes the
	 * same lock: an addition that started before the removal moves its files first and inserts the rows
	 * afterwards, so without the lock those rows - and the files behind them - would be written onto an
	 * office nobody has any more and nothing would ever come back for them.
	 *
	 * The office of transfer is held as well, so it cannot be removed while the staff moves into it. The
	 * two locks are taken in the order of the identifier whichever of the offices is removed: two removals
	 * heading into each other then queue for the same first lock instead of each holding the one the other
	 * waits for. Whether the offices exist is asked only once both are held, and from the master.
	 */
	private function remove(int $officeId, ?int $targetOfficeId): Result
	{
		$result = new Result();

		$lockOrder = $targetOfficeId === null
			? [$officeId]
			: [min($officeId, $targetOfficeId), max($officeId, $targetOfficeId)]
		;
		$lockedIds = [];

		foreach ($lockOrder as $lockId)
		{
			if (!$this->officeRepository->lock($lockId))
			{
				$this->unlockInReverse($lockedIds);
				$this->logFailure(__FUNCTION__, 'office ' . $lockId . ' is held by another operation');

				return $result->addError($this->getSaveFailedError());
			}

			$lockedIds[] = $lockId;
		}

		// Taking a lock switches the pool back off the master, and a replica seconds behind would still
		// show an office a removal running just before has taken.
		$this->useMasterOnly(true);

		try
		{
			$removeResult = $this->removeHeld($officeId, $targetOfficeId);
		}
		finally
		{
			$this->useMasterOnly(false);
			$this->unlockInReverse($lockedIds);
		}

		if (!$removeResult->isSuccess())
		{
			return $removeResult;
		}

		OfficesCountProvider::clearCache();

		$fileIds = $removeResult->getData()['fileIds'];
		$snapshotFileId = $removeResult->getData()['snapshotFileId'];
		$movedCount = $removeResult->getData()['movedCount'];

		// The files leave the storage past the transaction, so a failure there orphans them instead of
		// leaving the office half deleted. Inside the transaction a rollback would undo a removal that
		// already happened, so the step stays out here and answers for itself: the office is gone either
		// way, and a refusal would only send the client back for an office nobody has any more.
		try
		{
			$this->photoService->deleteFiles($fileIds);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}

		try
		{
			if ($snapshotFileId !== null)
			{
				$this->officeSnapshotService->deleteFile($snapshotFileId);
			}
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}

		// A client drops the removed office first and then reads the one its people went to. An office
		// that got nobody working shows no change, and it is not announced.
		$this->changeNotifier->notifyDeleted($officeId);
		if ($targetOfficeId !== null && $movedCount > 0)
		{
			$this->changeNotifier->notifyUpdated($targetOfficeId);
		}

		// Every requester costs a call to the messenger and one to the push channel, so an office many people
		// asked to see would keep the answer to its removal waiting on them: they are paid after the answer.
		$pendingRequests = $removeResult->getData()['pendingRequests'];
		$officeTitle = $removeResult->getData()['officeTitle'];
		$assignedRequesterIds = $removeResult->getData()['assignedRequesterIds'];
		if ($pendingRequests !== [] || $assignedRequesterIds !== [])
		{
			$this->addBackgroundJob(fn () => $this->settleAccessRequests(
				$officeId,
				$officeTitle,
				$pendingRequests,
				$targetOfficeId,
				$assignedRequesterIds,
			));
		}

		return $result->setData([
			'id' => $officeId,
			'targetOfficeId' => $targetOfficeId,
			'movedCount' => $movedCount,
			'fileIds' => $fileIds,
		]);
	}

	/**
	 * Tells those who waited for a decision on the removed office that it is gone, and annuls the requests
	 * of the moved to the office of transfer: they see its staff now, the way an assignment by hand lets
	 * them. Runs past the answer to the removal, which is committed by then, so a failure is logged and
	 * takes nothing back.
	 *
	 * @param array<array{id: int, userId: int, revision: int}> $pendingRequests
	 * @param int[] $assignedRequesterIds
	 */
	private function settleAccessRequests(
		int $officeId,
		string $officeTitle,
		array $pendingRequests,
		?int $targetOfficeId,
		array $assignedRequesterIds,
	): void
	{
		try
		{
			$this->accessRequestCanceller->afterOfficeDeleted($pendingRequests, $officeId, $officeTitle);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}

		if ($targetOfficeId !== null)
		{
			$this->accessRequestCanceller->cancelForAssigned($targetOfficeId, $assignedRequesterIds);
		}
	}

	/**
	 * Both offices are held by now, so neither can disappear between being found and the transaction.
	 */
	private function removeHeld(int $officeId, ?int $targetOfficeId): Result
	{
		$result = new Result();

		$office = $this->officeRepository->getById($officeId);
		if ($office === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		if ($targetOfficeId === null)
		{
			return $this->removeCard($officeId, null, (string)$office->getTitle());
		}

		if ($this->officeRepository->getById($targetOfficeId) === null)
		{
			return $result->addError($this->getTransferTargetNotFoundError());
		}

		// The staff of another office is written into the target, which is an edit of the target.
		if (!$this->hasAccess(OfficeAction::UPDATE, $targetOfficeId))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		return $this->removeCard($officeId, $targetOfficeId, (string)$office->getTitle());
	}

	/**
	 * @param string $officeTitle the title the office had, passed on for the requesters told past the commit
	 * @return Result Data: fileIds - the files of the office, named while the rows still point at them;
	 * movedCount - the working employees moved into the office of transfer, zero without one;
	 * assignedRequesterIds - those of the moved who were waiting for a decision on the office of transfer;
	 * pendingRequests - the requests to see its staff that were waiting for a decision; officeTitle.
	 */
	private function removeCard(int $officeId, ?int $targetOfficeId, string $officeTitle): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			// The files are named while the rows still point at them: after the commit nothing does.
			$fileIds = $this->getFileIds($officeId);
			$snapshotFileId = $this->officeRepository->getSnapshotFileIdForUpdate($officeId);

			// Counted before the move: afterwards the staff of the office is mixed with the one of the target.
			$movedCount = $targetOfficeId === null ? 0 : $this->officeEmployeeRepository->countByOfficeId($officeId);

			$photoResult = $this->officePhotoRepository->deleteByOfficeId($officeId);
			if (!$photoResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $photoResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			// The staff of the office goes in the same transaction as the card and under the same lock:
			// a transaction of its own beside this one would mean an office nobody has any more with a
			// staff still pointing at it, and nothing would ever come back for those rows. A transfer
			// holds to the same rule - the move is taken back with the card if anything after it fails.
			$employeeResult = $targetOfficeId === null
				? $this->officeEmployeeRepository->deleteByOfficeId($officeId)
				: $this->officeEmployeeRepository->moveAllToOffice($officeId, $targetOfficeId, $this->userId)
			;
			if (!$employeeResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $employeeResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			// The move names nobody, so the moved who wait for a decision on the target are read here, where
			// the moved rows are seen, and told past the commit.
			$assignedRequesterIds = $targetOfficeId === null
				? []
				: $this->accessRequestCanceller->findAssignedRequesters($targetOfficeId)
			;

			$pendingRequests = $this->accessRequestCanceller->takeForDeletedOffice($officeId);

			$deleteResult = $this->officeRepository->delete($officeId);
			if (!$deleteResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $deleteResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return $result->addError($this->getSaveFailedError());
		}

		return $result->setData([
			'fileIds' => $fileIds,
			'snapshotFileId' => $snapshotFileId,
			'movedCount' => $movedCount,
			'assignedRequesterIds' => $assignedRequesterIds,
			'pendingRequests' => $pendingRequests,
			'officeTitle' => $officeTitle,
		]);
	}

	/**
	 * @param int[] $lockedIds in the order the locks were taken
	 */
	private function unlockInReverse(array $lockedIds): void
	{
		foreach (array_reverse($lockedIds) as $lockedId)
		{
			$this->officeRepository->unlock($lockedId);
		}
	}

	protected function addBackgroundJob(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	/**
	 * Kept behind a method of its own, as in {@see Update}: a test has a single connection and no replica.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	private function getTransferTargetNotFoundError(): Error
	{
		return new Error('Office to transfer the staff to not found', OfficeErrorCode::TRANSFER_TARGET_NOT_FOUND);
	}

	/**
	 * @return int[]
	 */
	private function getFileIds(int $officeId): array
	{
		return array_map(
			static fn (OfficePhoto $photo): int => (int)$photo->getFileId(),
			$this->officePhotoRepository->getListByOfficeId($officeId),
		);
	}
}
