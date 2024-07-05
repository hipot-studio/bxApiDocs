<?php

namespace Bitrix\StaffTrack\Shift\Command;

use Bitrix\Main;
use Bitrix\Stafftrack\Integration\Pull;
use Bitrix\StaffTrack\Shift\ShiftDto;
use Bitrix\StaffTrack\Shift\Observer;

class Delete extends AbstractCommand
{
	/**
	 * @param ShiftDto $shiftDto
	 * @return Main\Result
	 */
	public function execute(ShiftDto $shiftDto): Main\Result
	{
		$result = new Main\Result();

		$this->shiftDto = $shiftDto;
		$shift = $this->mapper->createEntityFromDto($this->shiftDto);

		$deleteResult = $shift->delete();
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		try
		{
			$this->notify(...$this->observers);
		}
		catch (\Throwable $exception)
		{
			$result->addError(Main\Error::createFromThrowable($exception));
		}

		$this->sendPushToDepartment(Pull\PushCommand::SHIFT_DELETE);

		return $result;
	}
}