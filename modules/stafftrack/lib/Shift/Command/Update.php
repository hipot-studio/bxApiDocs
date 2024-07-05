<?php

namespace Bitrix\StaffTrack\Shift\Command;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Stafftrack\Integration\Pull;
use Bitrix\StaffTrack\Model\ShiftTable;
use Bitrix\StaffTrack\Shift\Observer;
use Bitrix\StaffTrack\Shift\ShiftDto;

class Update extends AbstractCommand
{
	/**
	 * @param ShiftDto $shiftDto
	 * @return Result
	 */
	public function execute(ShiftDto $shiftDto): Result
	{
		$result = new Result();

		$this->shiftDto = $shiftDto;

		$shift = $this->mapper->createEntityFromDto($this->shiftDto);
		$updateResult = ShiftTable::update($shiftDto->id, [
			'STATUS' => $shiftDto->status,
		]);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		try
		{
			$this->notify(...$this->observers);
		}
		catch (\Throwable $exception)
		{
			$result->addError(Error::createFromThrowable($exception));
		}

		$this->sendPushToDepartment(Pull\PushCommand::SHIFT_UPDATE);

		return $result->setData([
			'shift' => $shift,
		]);
	}

	/**
	 * @return void
	 */
	protected function init(): void
	{
		parent::init();

		$this->addObserver(new Observer\Message\Update());
		$this->addObserver(new Observer\Cancellation\Update());
	}
}