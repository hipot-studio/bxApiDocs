<?php

namespace Bitrix\StaffTrack\Internal\Repository\Mapper;

use Bitrix\StaffTrack\Internal\Model\CheckIn;
use Bitrix\StaffTrack\Public\Command\CheckInDto;

class CheckInMapper
{
	public function createEntityFromDto(CheckInDto $checkInDto): CheckIn
	{
		$checkIn = (new CheckIn(false))
			->setUserId($checkInDto->userId)
			->setEntityType($checkInDto->entityType)
			->setLatitude($checkInDto->latitude)
			->setLongitude($checkInDto->longitude)
			->setDescription($checkInDto->description)
			->setAddress($checkInDto->address)
			->setMessageIds($checkInDto->messageIds)
			->setUserTimezone($checkInDto->userTimezone)
			->setFileIds($checkInDto->fileIds)
		;

		if (!empty($checkInDto->id))
		{
			$checkIn->setId($checkInDto->id);
		}

		if ($checkInDto->dateCreate !== null)
		{
			$checkIn->setDateCreate($checkInDto->dateCreate);
		}

		return $checkIn;
	}

	public static function createDtoFromEntity(CheckIn $checkIn): CheckInDto
	{
		return (new CheckInDto())
			->setId($checkIn->getId())
			->setUserId($checkIn->getUserId())
			->setDateCreate($checkIn->getDateCreate())
			->setEntityType($checkIn->getEntityType())
			->setLatitude($checkIn->getLatitude())
			->setLongitude($checkIn->getLongitude())
			->setDescription($checkIn->getDescription())
			->setAddress($checkIn->getAddress())
			->setMessageIds($checkIn->getMessageIds())
			->setUserTimezone($checkIn->getUserTimezone())
			->setFileIds($checkIn->getFileIds())
		;
	}
}
