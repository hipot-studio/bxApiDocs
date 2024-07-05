<?php

namespace Bitrix\StaffTrack\Type;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type;

class DateTime
{
	public const DATETIME_FORMAT = 'd.m.Y H:i:s';

	public function __construct(public string $dateTime) {}

	/**
	 * @throws ObjectException
	 */
	public static function createFromTimezoneOffset(int $timezoneOffset): self
	{
		$currentTime = new Type\DateTime(null, null, new \DateTimeZone('UTC'));

		$dateTime = Type\DateTime::createFromTimestamp($currentTime->getTimestamp() + $timezoneOffset)
			->setTimeZone(new \DateTimeZone('UTC'))
		;

		return new self($dateTime->format(self::DATETIME_FORMAT));
	}

	/**
	 * @throws ObjectException
	 */
	public function getDate(): Type\Date
	{
		$date = new Type\DateTime($this->dateTime, self::DATETIME_FORMAT);

		return new Type\Date($date);
	}

	/**
	 * @throws ObjectException
	 */
	public function getOffset(): int
	{
		$date = new Type\DateTime($this->dateTime, self::DATETIME_FORMAT, new \DateTimeZone('UTC'));
		$utcDate = new Type\DateTime(null, null, new \DateTimeZone('UTC'));

		$fiveMinutes = 5 * 60;

		return (int)(round(($date->getTimestamp() - $utcDate->getTimestamp()) / $fiveMinutes) * $fiveMinutes);
	}

	public function toString(): string
	{
		return $this->dateTime;
	}
}