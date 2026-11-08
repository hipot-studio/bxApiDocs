<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\OfficeAccessController;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\PhotoService;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * Base of the write operations on the office directory. Every command keeps the same order: the right
 * of the caller first, the fields of the card second, the storage last. The caller is named by the
 * constructor rather than read from the request, so the same command serves an ajax action, a console
 * script and a test.
 */
abstract class AbstractCommand
{
	public const ERROR_ACCESS_DENIED = OfficeErrorCode::ACCESS_DENIED;
	public const ERROR_NOT_FOUND = OfficeErrorCode::NOT_FOUND;
	public const ERROR_SAVE_FAILED = 'STAFFTRACK_OFFICE_SAVE_FAILED';

	protected OfficeRepository $officeRepository;
	protected PhotoService $photoService;

	public function __construct(protected readonly int $userId)
	{
		$this->init();
	}

	abstract public function execute(OfficeDto $officeDto): Result;

	protected function init(): void
	{
		$this->officeRepository = new OfficeRepository();
		$this->photoService = new PhotoService();
	}

	/**
	 * Photos are hung on a card that is already written: the transaction of the card is committed by
	 * the time they arrive, and nothing that happens here undoes it. Both forms a failure takes - a
	 * refusal of the domain and an exception of the disk, the uploader or the storage - are logged and
	 * handed back, and what to do with them is the business of the command: {@see Add} carries on
	 * rather than send the client into a second attempt that would leave two offices behind, while
	 * {@see Update} answers with them.
	 *
	 * @param string[] $photoTokens
	 */
	protected function attachPhotos(int $officeId, array $photoTokens): Result
	{
		$result = new Result();

		if ($photoTokens === [])
		{
			return $result;
		}

		try
		{
			$photoResult = $this->photoService->addPhotos($officeId, $photoTokens, $this->userId);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return $result->addError($this->getPhotoFailedError());
		}

		if (!$photoResult->isSuccess())
		{
			$this->logFailure(__FUNCTION__, implode('; ', $photoResult->getErrorMessages()));

			return $result->addErrors($photoResult->getErrors());
		}

		return $result;
	}

	/**
	 * The one mandatory check of the domain: neither the controller nor the uploader stands in for it.
	 */
	protected function hasAccess(string $action, int $officeId = 0): bool
	{
		return OfficeAccessController::getInstance($this->userId)
			->check($action, OfficeModel::createFromId($officeId))
		;
	}

	protected function getAccessDeniedError(): Error
	{
		return new Error('Access to the office directory denied', self::ERROR_ACCESS_DENIED);
	}

	protected function getNotFoundError(): Error
	{
		return new Error('Office not found', self::ERROR_NOT_FOUND);
	}

	/**
	 * What a client is told about a failure of the storage: the reason of it stays in the log.
	 */
	protected function getSaveFailedError(): Error
	{
		return new Error('Failed to save the office', self::ERROR_SAVE_FAILED);
	}

	/**
	 * What a client is told when the photos of a card were raised rather than refused: the reason of
	 * it stays in the log.
	 */
	protected function getPhotoFailedError(): Error
	{
		return new Error('Failed to save the photos of the office', PhotoService::ERROR_SAVE_FAILED);
	}

	/**
	 * {@see OfficeDto} raises its exceptions with a machine code of the card contract as the message.
	 */
	protected function getInvalidDtoError(InvalidDtoException $exception): Error
	{
		return new Error('Invalid office card', $exception->getMessage());
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a storage error carries
	 * the query it failed on, and that query carries the coordinates of the office.
	 */
	protected function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}

	/**
	 * The card as the storage keeps it. Who wrote it and when is added by the command doing the write.
	 */
	protected function buildCardFields(OfficeDto $officeDto): array
	{
		return [
			'TITLE' => $officeDto->title,
			'LATITUDE' => $officeDto->latitude,
			'LONGITUDE' => $officeDto->longitude,
			'GEO_RADIUS' => $officeDto->geoRadius,
			'ADDRESS' => $officeDto->address,
			'WORK_TIME_FROM' => $officeDto->workTimeFrom,
			'WORK_TIME_TO' => $officeDto->workTimeTo,
		];
	}
}
