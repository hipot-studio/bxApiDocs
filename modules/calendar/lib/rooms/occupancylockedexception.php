<?php

namespace Bitrix\Calendar\Rooms;

final class OccupancyLockedException extends \RuntimeException
{
	public const ERROR_CODE = 'CALENDAR_LOCATION_OCCUPANCY_LOCKED';

	public function __construct(string $message = 'Meeting room occupancy is locked.', ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}

	public function getErrorCode(): string
	{
		return self::ERROR_CODE;
	}
}
