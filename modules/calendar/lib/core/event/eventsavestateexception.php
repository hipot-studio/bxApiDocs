<?php

namespace Bitrix\Calendar\Core\Event;

use Bitrix\Main\Localization\Loc;

final class EventSaveStateException extends \RuntimeException
{
	public const EVENT_NOT_AVAILABLE = 'CALENDAR_EVENT_NOT_AVAILABLE';
	public const ROOM_RESERVATION_NOT_AVAILABLE = 'CALENDAR_ROOM_RESERVATION_NOT_AVAILABLE';

	private function __construct(
		private readonly string $errorCode,
		string $message,
		private readonly bool $eventMissing = false,
	)
	{
		parent::__construct($message);
	}

	public static function eventNotAvailable(bool $eventMissing = false): self
	{
		Loc::loadMessages(__DIR__ . '/../../../classes/general/calendar.php');

		return new self(self::EVENT_NOT_AVAILABLE, Loc::getMessage('EC_ACCESS_DENIED'), $eventMissing);
	}

	public static function roomReservationNotAvailable(): self
	{
		Loc::loadMessages(__DIR__ . '/../../../classes/general/calendar.php');

		return new self(self::ROOM_RESERVATION_NOT_AVAILABLE, Loc::getMessage('EC_MR_RESERVE_ERR'));
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function isEventMissing(): bool
	{
		return $this->eventMissing;
	}
}
