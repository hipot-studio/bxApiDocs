<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Internal\Model\OfficePhoto;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficePhotoRepository;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeSnapshotService;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Provider\OfficesCountProvider;

/**
 * Removes an office for good: a closed office is deleted rather than hidden, and the check-ins its
 * employees left behind are not touched - they never pointed at the office. Everything that hangs off
 * the card goes in the same transaction as the card itself, and the rows of the neighbouring tasks of
 * the directory are to be removed here as well.
 */
class Delete extends AbstractCommand
{
	protected OfficePhotoRepository $officePhotoRepository;
	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected OfficeSnapshotService $officeSnapshotService;

	protected function init(): void
	{
		parent::init();

		$this->officePhotoRepository = new OfficePhotoRepository();
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->officeSnapshotService = new OfficeSnapshotService();
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

		if ($this->officeRepository->getById($officeDto->id) === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		return $this->remove($officeDto->id);
	}

	/**
	 * The card is removed while holding the lock of the office, and the addition of photos takes the
	 * same lock: an addition that started before the removal moves its files first and inserts the rows
	 * afterwards, so without the lock those rows - and the files behind them - would be written onto an
	 * office nobody has any more and nothing would ever come back for them.
	 */
	private function remove(int $officeId): Result
	{
		$result = new Result();

		if (!$this->officeRepository->lock($officeId))
		{
			$this->logFailure(__FUNCTION__, 'office ' . $officeId . ' is held by another operation');

			return $result->addError($this->getSaveFailedError());
		}

		try
		{
			$removeResult = $this->removeCard($officeId);
		}
		finally
		{
			$this->officeRepository->unlock($officeId);
		}

		if (!$removeResult->isSuccess())
		{
			return $removeResult;
		}

		OfficesCountProvider::clearCache();

		$fileIds = $removeResult->getData()['fileIds'];
		$snapshotFileId = $removeResult->getData()['snapshotFileId'];

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

		return $result->setData([
			'id' => $officeId,
			'fileIds' => $fileIds,
		]);
	}

	/**
	 * @return Result Data: fileIds - the files of the office, named while the rows still point at them.
	 */
	private function removeCard(int $officeId): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			// The files are named while the rows still point at them: after the commit nothing does.
			$fileIds = $this->getFileIds($officeId);
			$snapshotFileId = $this->officeRepository->getSnapshotFileIdForUpdate($officeId);

			$photoResult = $this->officePhotoRepository->deleteByOfficeId($officeId);
			if (!$photoResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $photoResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			// The staff of the office goes in the same transaction as the card and under the same lock:
			// a transaction of its own beside this one would mean an office nobody has any more with a
			// staff still pointing at it, and nothing would ever come back for those rows.
			$employeeResult = $this->officeEmployeeRepository->deleteByOfficeId($officeId);
			if (!$employeeResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $employeeResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

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
		]);
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
