<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileSaver;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;

class Add extends AbstractCommand
{
	public function execute(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		$result = new Result();

		if (!Loader::includeModule('location'))
		{
			return $result->addError(new Error('Module "location" not installed'));
		}

		$this->checkInDto = clone $checkInDto;
		$this->checkInDto->setId(0);

		try
		{
			$this->checkInDto->validateAdd();
		}
		catch (InvalidDtoException $e)
		{
			return $result->addError(new Error($e->getMessage()));
		}

		$this->normalizeFileIds();
		$this->processUploadedFiles($uploadedFiles);
		$this->fillAddressFromCache();

		if ($this->checkInDto->dialogIds && $this->checkInDto->snapshotUrl)
		{
			$sendResult = $this->tryToSendMessage();
			if (!$sendResult->isSuccess())
			{
				return $result->addErrors($sendResult->getErrors());
			}

			$this->checkInDto->messageIds = $sendResult->getData()['messageIds'];
		}

		$this->checkInDto->description = Emoji::encode($this->checkInDto->description);

		$saveResult = $this->saveCheckIn();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		MessageService::saveLastSelectedDialogId($this->checkInDto->userId, $this->checkInDto->dialogIds ?? []);
		if (
			$this->checkInDto->entityType === CheckInType::MANUAL->value
			&& !$this->checkInDto->skipWorkDayStart
		)
		{
			WorkDayService::startWorkDay();
		}
		$this->sendPushToDepartment(Pull\PushCommand::CHECK_IN_ADD);

		return $result->setData([
			'checkIn' => $this->prepareResult(),
		]);
	}

	/**
	 * @throws LoaderException
	 */
	private function tryToSendMessage(): Result
	{
		return MessageService::send($this->checkInDto);
	}

	private function normalizeFileIds(): void
	{
		$this->checkInDto->setFileIds(
			FileSaver::normalizeFileIds($this->checkInDto->fileIds),
		);
	}

	private function processUploadedFiles(array $uploadedFiles): void
	{
		if (empty($uploadedFiles))
		{
			return;
		}

		$saveResult = (new FileSaver())->saveUploadedFiles($uploadedFiles, $this->checkInDto->userId);
		if (!$saveResult->isSuccess())
		{
			return;
		}

		$newFileIds = $saveResult->getData()['fileIds'];
		if (!empty($newFileIds))
		{
			$this->checkInDto->setFileIds(
				array_merge($this->checkInDto->fileIds, $newFileIds),
			);
		}
	}

	private function fillAddressFromCache(): void
	{
		if (!empty($this->checkInDto->address))
		{
			return;
		}

		$geohash = Geohash::encode(
			(float)$this->checkInDto->latitude,
			(float)$this->checkInDto->longitude,
		);
		$address = AddressCache::get($geohash);

		if ($address !== null)
		{
			$this->checkInDto->setAddress($address);
		}
	}

	private function saveCheckIn(): Result
	{
		$result = new Result();

		if ($this->checkInDto->dateCreate === null)
		{
			$this->checkInDto->setDateCreate(new DateTime());
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$checkIn = $this->mapper->createEntityFromDto($this->checkInDto);
			$saveResult = $checkIn->save();
			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result->addErrors($saveResult->getErrors());
			}

			$this->checkInDto->id = (int)$saveResult->getId();
			$this->updateDailyStats();
			$connection->commitTransaction();
		}
		catch (\Bitrix\Main\DB\SqlException $e)
		{
			$connection->rollbackTransaction();

			return $result->addError(new Error('Failed to save check-in'));
		}

		return $result;
	}

	private function updateDailyStats(): void
	{
		$timestamp = $this->checkInDto->dateCreate?->getTimestamp() ?? time();

		(new DailyStatsUpdater())->update(
			$this->checkInDto->userId,
			$this->checkInDto->id,
			$this->checkInDto->entityType,
			(string)($this->checkInDto->description ?? ''),
			(int)($this->checkInDto->userTimezone ?? 0),
			$timestamp,
		);
	}

	private function prepareResult(): array
	{
		$checkInData = [
			'ID' => $this->checkInDto->id,
			'USER_ID' => $this->checkInDto->userId,
			'ENTITY_TYPE' => $this->checkInDto->entityType,
			'LATITUDE' => $this->checkInDto->latitude,
			'LONGITUDE' => $this->checkInDto->longitude,
			'DATE_CREATE' => $this->checkInDto->dateCreate,
			'DESCRIPTION' => $this->checkInDto->description,
			'ADDRESS' => $this->checkInDto->address,
		];

		$normalized = CheckInNormalizer::normalize(
			$checkInData,
			(int)($this->checkInDto->userTimezone ?? 0),
		);

		return $normalized;
	}
}
