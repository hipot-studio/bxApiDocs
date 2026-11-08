<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Public\Provider\OfficesCountProvider;

/**
 * Creates an office. A form sent twice creates two offices: the domain does not recognise a repeat,
 * and a duplicate is noticed and removed by a person.
 */
class Add extends AbstractCommand
{
	/**
	 * @param string[] $photoTokens Optional: a card may be saved first and its photos added later.
	 */
	public function execute(OfficeDto $officeDto, array $photoTokens = []): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::ADD))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validateAdd();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		$saveResult = $this->save($officeDto);
		if ($saveResult->isSuccess())
		{
			OfficesCountProvider::clearCache();

			// The refusal of the photos is dropped on purpose: the office is written and committed by
			// now, and answering a failure would send the client into a second attempt that leaves two
			// offices behind. What went wrong is in the log, and the card can be read back for its photos.
			$this->attachPhotos((int)$saveResult->getData()['id'], $photoTokens);
		}

		return $saveResult;
	}

	private function save(OfficeDto $officeDto): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$addResult = $this->officeRepository->add($this->buildFields($officeDto));
			if (!$addResult->isSuccess())
			{
				$connection->rollbackTransaction();
				$this->logFailure(__FUNCTION__, implode('; ', $addResult->getErrorMessages()));

				return $result->addError($this->getSaveFailedError());
			}

			$connection->commitTransaction();

			return $result->setData([
				'id' => (int)$addResult->getId(),
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
				'CREATED_BY' => $this->userId,
				'DATE_CREATE' => new DateTime(),
			],
		);
	}
}
