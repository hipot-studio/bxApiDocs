<?php

namespace Bitrix\Calendar\Rooms;

class OccupancyCheckerException extends \Bitrix\Main\SystemException
{
	public const ERROR_CODE = 'CALENDAR_LOCATION_OCCUPANCY_CONFLICT';
	public const INVALID_INTERVAL_ERROR_CODE = 'CALENDAR_LOCATION_OCCUPANCY_INVALID_INTERVAL';

	public function __construct(
		string $message,
		private readonly array $occupancyData = [],
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, '', 0, $previous);
	}

	public function getOccupancyData(): array
	{
		return $this->occupancyData;
	}

	public function isInvalidInterval(): bool
	{
		return ($this->occupancyData['invalidInterval'] ?? false) === true;
	}
}
