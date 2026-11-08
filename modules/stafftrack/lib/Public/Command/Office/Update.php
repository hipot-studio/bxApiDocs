<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * Rewrites the card of an office. Coordinates are a field like any other - moving an office is an
 * edit of its card, not a new office. The card is written whole and the last write wins: two people
 * editing one office at once is not a case the domain arbitrates.
 */
class Update extends AbstractCommand
{
	/**
	 * @param string[] $photoTokens Optional, and never a rewrite: photos already on the office stay
	 *                              there, and these are added beside them (I-07 BRIEF).
	 */
	public function execute(OfficeDto $officeDto, array $photoTokens = []): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::UPDATE, $officeDto->id))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validateUpdate();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		// An update of a row that is not there leaves the storage untouched and reports success, so the
		// office is looked up before the write rather than after it.
		if ($this->officeRepository->getById($officeDto->id) === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		$saveResult = $this->save($officeDto);
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		// A repeated edit leaves no second office behind, unlike a repeated creation, so the photos that
		// did not make it are worth a refusal: the client is told about them instead of reading the card
		// back and finding them missing.
		return $saveResult->addErrors($this->attachPhotos($officeDto->id, $photoTokens)->getErrors());
	}

	private function save(OfficeDto $officeDto): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$updateResult = $this->officeRepository->update($officeDto->id, $this->buildFields($officeDto));
			if (!$updateResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $updateResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			$connection->commitTransaction();

			return $result->setData([
				'id' => $officeDto->id,
			]);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return $result->addError($this->getSaveFailedError());
		}
	}

	private function buildFields(OfficeDto $officeDto): array
	{
		return array_merge(
			$this->buildCardFields($officeDto),
			[
				'UPDATED_BY' => $this->userId,
				'DATE_UPDATE' => new DateTime(),
			],
		);
	}
}
