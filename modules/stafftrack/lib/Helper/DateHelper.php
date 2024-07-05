<?php

namespace Bitrix\StaffTrack\Helper;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Trait\Singleton;

class DateHelper
{
	use Singleton;

	/** @var string  */
	public const DATE_FORMAT = 'd.m.Y';

	/** @var string  */
	public const CLIENT_DATE_FORMAT = 'D M d Y';

	/** @var string  */
	public const CLIENT_DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

	/** @var int[] */
	private array $timezoneOffset = [];

	private ?int $serverOffset = null;

	/**
	 * @param DateTime $dateTime
	 * @return DateTime
	 */
	public function getDateUtc(DateTime $dateTime): DateTime
	{
		return DateTime::createFromTimestamp($dateTime->getTimestamp() - $this->getServerOffset());
	}

	/**
	 * @param int $userId
	 * @return int
	 */
	public function getUserTimezoneOffsetUtc(int $userId): int
	{
		if (isset($this->timezoneOffset[$userId]))
		{
			return $this->timezoneOffset[$userId];
		}

		$offsetOption = OptionProvider::getInstance()->getOption($userId, Option::TIMEZONE_OFFSET);

		$this->timezoneOffset[$userId] = (int)($offsetOption?->getValue() ?? date('Z'));

		return $this->timezoneOffset[$userId];
	}

	/**
	 * @param string|null $date
	 * @param string $format
	 * @return DateTime
	 */
	public function getServerDate(?string $date = null, string $format = self::DATE_FORMAT): DateTime
	{
		try
		{
			$dateTime = new DateTime(
				$date,
				$format,
			);
		}
		catch (ObjectException $e)
		{
			$dateTime = new DateTime(
				null,
				null,
			);
		}
		finally
		{
			return $dateTime;
		}
	}

	private function getServerOffset(): int
	{
		if ($this->serverOffset === null)
		{
			$this->serverOffset = (new \DateTime())->getOffset();
		}

		return $this->serverOffset;
	}
}