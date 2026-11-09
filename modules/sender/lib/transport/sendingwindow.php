<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Sender\Transport;

/**
 * Daily sending window set by its owner in their own time zone.
 *
 * Every moment is a server timestamp: the owner offset is applied inside, so callers neither convert
 * time nor know about the owner.
 */
class SendingWindow
{
	private int $startSec;
	private int $endSec;
	private int $offsetSeconds;

	public function __construct(string $startText, string $endText, int $offsetSeconds = 0)
	{
		$startSec = self::secondsOfDay($startText);
		$endSec = self::secondsOfDay($endText);

		// reversed boundaries are swapped, not read as an overnight window: legacy behaviour kept as is
		$this->startSec = min($startSec, $endSec);
		$this->endSec = max($startSec, $endSec);
		$this->offsetSeconds = $offsetSeconds;
	}

	/**
	 * A moment matching a boundary is out of the window.
	 */
	public function isOpenAt(int $serverNow): bool
	{
		$nowSec = self::secondsOfDayOfMoment($serverNow + $this->offsetSeconds);

		return $this->startSec < $nowSec && $nowSec < $this->endSec;
	}

	/**
	 * The opening is built on the wall clock, so a clock change before it keeps the start time.
	 */
	public function nextOpeningAt(int $serverNow): int
	{
		$ownerNow = $serverNow + $this->offsetSeconds;
		$opening = $this->openingOnDayOf($ownerNow, 0);
		if ($opening < $ownerNow)
		{
			$opening = $this->openingOnDayOf($ownerNow, 1);
		}

		return $opening - $this->offsetSeconds;
	}

	private function openingOnDayOf(int $timestamp, int $daysAhead): int
	{
		return mktime(
			intdiv($this->startSec, 3600),
			intdiv($this->startSec % 3600, 60),
			0,
			(int)date('n', $timestamp),
			(int)date('j', $timestamp) + $daysAhead,
			(int)date('Y', $timestamp),
		);
	}

	/**
	 * Boundaries are stored in the short time format of the portal culture, which is not always H:i.
	 */
	private static function secondsOfDay(string $text): int
	{
		$timestamp = strtotime($text);
		if ($timestamp === false)
		{
			return 0;
		}

		return (int)date('H', $timestamp) * 3600 + (int)date('i', $timestamp) * 60;
	}

	private static function secondsOfDayOfMoment(int $timestamp): int
	{
		return (int)date('H', $timestamp) * 3600
			+ (int)date('i', $timestamp) * 60
			+ (int)date('s', $timestamp);
	}
}
