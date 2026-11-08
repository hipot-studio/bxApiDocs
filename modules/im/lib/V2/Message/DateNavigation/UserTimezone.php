<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\DateNavigation;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * The time the navigation counts days in.
 *
 * Two inputs answer the same questions. A timezone identifier carries the offset of every single
 * date and is the preferred one. A bare UTC offset is the released contract: it describes a whole
 * request by one number, which a range crossing a clock change cannot be described by, and is kept
 * for the clients still speaking it.
 */
class UserTimezone
{
	private const MAX_TIMEZONE_OFFSET = 50400;
	private const MIN_TIMEZONE_OFFSET = -43200;
	private const DATE_FORMAT = 'Y-m-d';
	private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
	private const DAY_START_TIME = ' 00:00:00';

	private static ?array $knownIdentifiers = null;

	private function __construct(
		private readonly ?\DateTimeZone $timezone,
		private readonly int $offsetFromServerTime,
	){}

	/**
	 * Only identifiers of the timezone database are taken: DateTimeZone would also accept '+05:30'
	 * or 'GMT+3', and such a value would slip past the offset bounds the released contract clamps to.
	 */
	public static function createById(string $timezoneId): ?self
	{
		self::$knownIdentifiers ??= array_flip(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC));

		if (!isset(self::$knownIdentifiers[$timezoneId]))
		{
			return null;
		}

		return new self(new \DateTimeZone($timezoneId), 0);
	}

	// Accepts the client UTC offset in seconds as the source of truth; falls back to the portal user timezone.
	public static function createByClientOffset(?int $clientUtcOffset): self
	{
		if ($clientUtcOffset === null)
		{
			return new self(null, \CTimeZone::GetOffset());
		}

		$clientUtcOffset = max(min($clientUtcOffset, self::MAX_TIMEZONE_OFFSET), self::MIN_TIMEZONE_OFFSET);

		return new self(null, $clientUtcOffset - (int)date('Z'));
	}

	/** The server moment the day of the user starts at. */
	public function getDayStart(Date $day): DateTime
	{
		if ($this->timezone === null)
		{
			return DateTime::createFromTimestamp($day->getTimestamp() - $this->offsetFromServerTime);
		}

		return new DateTime(
			$day->format(self::DATE_FORMAT) . self::DAY_START_TIME,
			self::DATE_TIME_FORMAT,
			$this->timezone,
		);
	}

	/** The day of the user a server moment belongs to. */
	public function getDay(DateTime $moment): string
	{
		if ($this->timezone === null)
		{
			return DateTime::createFromTimestamp($moment->getTimestamp() + $this->offsetFromServerTime)
				->format(self::DATE_FORMAT)
			;
		}

		return (clone $moment)->setTimeZone($this->timezone)->format(self::DATE_FORMAT);
	}

	/**
	 * The [$from, $to) range cut into parts neither the user nor the server changes their UTC offset
	 * over, so one shift maps every moment of a part onto the day of the user it truly belongs to.
	 * A range without a clock change stays a single part, and so does the whole offset contract.
	 *
	 * @return TimeSegment[]
	 */
	public function getSegments(DateTime $from, DateTime $to): array
	{
		$points = $this->getSegmentPoints($from->getTimestamp(), $to->getTimestamp());
		$segments = [];

		for ($index = 0, $lastIndex = count($points) - 1; $index < $lastIndex; $index++)
		{
			$segmentStart = DateTime::createFromTimestamp($points[$index]);
			$segments[] = new TimeSegment(
				$segmentStart,
				DateTime::createFromTimestamp($points[$index + 1]),
				$this->getUserTimeOffset($segmentStart),
			);
		}

		return $segments;
	}

	/**
	 * @return int[] ascending server timestamps bounding the parts, both ends of the range included.
	 */
	private function getSegmentPoints(int $from, int $to): array
	{
		if ($this->timezone === null || $from >= $to)
		{
			return [$from, $to];
		}

		$points = [$from, $to];
		$timezones = [$this->timezone, new \DateTimeZone(date_default_timezone_get())];

		foreach ($timezones as $timezone)
		{
			// the first entry is the state the range starts in and not a change inside it
			foreach ($timezone->getTransitions($from, $to) ?: [] as $transition)
			{
				if ($transition['ts'] > $from && $transition['ts'] < $to)
				{
					$points[] = $transition['ts'];
				}
			}
		}

		sort($points);

		return array_values(array_unique($points));
	}

	private function getUserTimeOffset(DateTime $moment): int
	{
		if ($this->timezone === null)
		{
			return $this->offsetFromServerTime;
		}

		return (clone $moment)->setTimeZone($this->timezone)->getOffset()
			- (clone $moment)->setDefaultTimeZone()->getOffset()
		;
	}
}
