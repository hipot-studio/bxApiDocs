<?php

namespace Bitrix\StaffTrack\Shift;

use Bitrix\StaffTrack\Model\Shift;

class ShiftMapper
{
	public function __construct()
	{

	}

	public function createEntityFromDto(ShiftDto $shiftDto): Shift
	{
		$shift = (new Shift(false))
			->setUserId($shiftDto->userId)
			->setShiftDate($shiftDto->shiftDate)
			->setDateCreate($shiftDto->dateCreate)
			->setLocation($shiftDto->location)
			->setStatus($shiftDto->status)
		;

		if (!empty($shiftDto->id))
		{
			$shift->setId($shiftDto->id);
		}

		return $shift;
	}

	public static function createDtoFromEntity(Shift $shift): ShiftDto
	{
		return (new ShiftDto())
			->setId($shift->getId())
			->setUserId($shift->getUserId())
			->setShiftDate($shift->getShiftDate())
			->setDateCreate($shift->getDateCreate())
			->setLocation($shift->getLocation())
			->setStatus($shift->getStatus())
		;
	}
}

