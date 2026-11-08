<?php

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Helper\DateHelper;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\Intranet\Settings\Tools\ToolsManager;

class WorkDayService extends BaseService
{
	/** @var ?\CTimeManUser */
	private static $timeManUser = null;

	public static function getWorkTime(int $userId): array
	{
		if (!self::isAvailable())
		{
			return [
				'isTimeManIntegrationEnabled' => false,
				'record' => [],
			];
		}

		$record = (new RecordProvider())->getCurrentRecord($userId);

		return [
			'isTimeManIntegrationEnabled' => static::shouldStartWorkDay(),
			'isNotWorkingDay' => self::isNotWorkingDay($userId),
			'record' => $record?->toArray(),
		];
	}

	public static function isNotWorkingDay(int $userId): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		try
		{
			$dateHelper = DateHelper::getInstance();

			return $dateHelper->isNotWorkingDay($dateHelper->getOffsetDate($userId));
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * Intervals the work day spent paused, in server time. A pause that is still running comes back with
	 * to = null: the day has not resumed yet, so the end of the interval is not known - and only the day
	 * being lived through has one of those, see foldWindowPauses().
	 *
	 * The narrow door, for a caller that wants the breaks and nothing else; the day answer needs the bounds
	 * as well and takes getDay(), where both come off one reading.
	 *
	 * @return array<int, array{from: int, to: ?int}>
	 */
	public static function getDayPauses(int $userId, int $timestamp, int $timezoneOffset): array
	{
		if (!static::isAvailable())
		{
			return [];
		}

		[$windowStart, $windowEnd] = self::getDayWindow($timestamp, $timezoneOffset);

		return self::foldWindowPauses(
			$userId,
			self::getWindowRecords($userId, $windowStart, $windowEnd),
			$windowStart,
			$windowEnd,
			self::isDayBeingLivedThrough($timestamp, $timezoneOffset),
		);
	}

	/**
	 * The work day as the check-in day answer needs it: its bounds in server time, whether it is over, and
	 * the moment it was closed at. A day that was never started has no start, a day that is still running
	 * has no end.
	 *
	 * A day of several shifts is one day, and it begins where the earliest of them did: the start is the
	 * lowest of the records that began inside the window, whatever order work time tracking hands them in.
	 * A shift started the night before is not one of them - it belongs to the day it began in.
	 *
	 * The two times are work time tracking's own, so it says who may have them: a reader without the right
	 * to the work time of that employee is answered neither of them. The right is asked only of a day that
	 * has something to withhold, so a day nobody worked costs nothing to refuse.
	 *
	 * A caller with nothing to place between the two times asks for the day without them: the right they
	 * cost is the resolve of an entire org structure, and it is not worth paying for a time nobody reads -
	 * and neither is the reading the start comes from.
	 *
	 * Two answers outlive the withheld bounds, and neither of them is one. Whether the day is over is how
	 * the ribbon tells a finished day from a running one, and withheld it would grow a live row on a day
	 * already over. `closedAtUnrestricted` is the moment of that closing as the day is READ by it - the stop
	 * left open at the end of a finished day ends there rather than at now, whoever is looking - and it is
	 * for the reading alone: the right is never asked of it, which is what its name says out loud. What an
	 * answer may carry is `end`, the same moment as far as this reader may have it.
	 *
	 * @return array{start: ?int, end: ?int, closed: bool, closedAtUnrestricted: ?int}
	 */
	public static function getDayBounds(
		int $userId,
		int $timestamp,
		int $timezoneOffset,
		bool $withBounds = true
	): array
	{
		$day = self::getDay($userId, $timestamp, $timezoneOffset, $withBounds, withPauses: false);

		return [
			'start' => $day['start'],
			'end' => $day['end'],
			'closed' => $day['closed'],
			'closedAtUnrestricted' => $day['closedAtUnrestricted'],
		];
	}

	/**
	 * The work day as one reading: the bounds above and the intervals it spent paused, off one window of
	 * records. Both halves are made of the same shifts, and asked apart they put the very same question to
	 * work time tracking twice - a duplicate neither module sees on its own, because it lives on the seam
	 * between them.
	 *
	 * The pauses are answered only to a caller that asked for them: a day with nothing to sign the silence
	 * of walks no event log.
	 *
	 * @return array{start: ?int, end: ?int, closed: bool, closedAtUnrestricted: ?int, pauses: array<int, array{from: int, to: ?int}>}
	 */
	public static function getDay(
		int $userId,
		int $timestamp,
		int $timezoneOffset,
		bool $withBounds = true,
		bool $withPauses = true
	): array
	{
		if (!static::isAvailable())
		{
			return ['start' => null, 'end' => null, 'closed' => false, 'closedAtUnrestricted' => null, 'pauses' => []];
		}

		[$windowStart, $windowEnd] = self::getDayWindow($timestamp, $timezoneOffset);
		$isBeingLivedThrough = self::isDayBeingLivedThrough($timestamp, $timezoneOffset);

		// The record open right now is the last shift of the day and says nothing about the ones before it,
		// so everything but the closing of a day still being lived through is read off the whole window - and
		// the window is read once for all of them.
		$windowRecords = ($withBounds || $withPauses || !$isBeingLivedThrough)
			? self::getWindowRecords($userId, $windowStart, $windowEnd)
			: []
		;

		$closingRecords = $isBeingLivedThrough ? self::getCurrentRecords($userId) : $windowRecords;
		$closedAt = self::findCloseTimestamp($closingRecords, $windowStart, $windowEnd);
		$closed = $closedAt !== null;

		$pauses = $withPauses
			? self::foldWindowPauses($userId, $windowRecords, $windowStart, $windowEnd, $isBeingLivedThrough, $closingRecords)
			: []
		;

		if (!$withBounds)
		{
			return ['start' => null, 'end' => null, 'closed' => $closed, 'closedAtUnrestricted' => $closedAt, 'pauses' => $pauses];
		}

		$start = self::findStartTimestamp($windowRecords, $windowStart, $windowEnd);

		if (($start !== null || $closedAt !== null) && !static::canReadWorkTime($userId))
		{
			return ['start' => null, 'end' => null, 'closed' => $closed, 'closedAtUnrestricted' => $closedAt, 'pauses' => $pauses];
		}

		return [
			'start' => $start,
			'end' => $closedAt,
			'closed' => $closed,
			'closedAtUnrestricted' => $closedAt,
			'pauses' => $pauses,
		];
	}

	public static function shouldStartWorkDay(): bool
	{
		if (!self::isAvailable())
		{
			return false;
		}

		return self::isTimeManIntegrationEnabled();
	}

	public static function changeTimemanIntegrationOption(bool $optionValue): void
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

		OptionService::getInstance()->save(
			$userId,
			Option::TIMEMAN_INTEGRATION_ENABLED,
			$optionValue ? 'Y' : 'N',
		);

		OptionProvider::getInstance()->invalidateCache($userId);
	}

	public static function startWorkDay(): void
	{
		if (!self::shouldStartWorkDay())
		{
			return;
		}

		$timeMan = self::getTimeManUser();
		if (!$timeMan?->isDayOpen())
		{
			$timeMan?->openDay(false, '', [
				'DEVICE' => ScheduleTable::ALLOWED_DEVICES_MOBILE,
			]);
		}
	}

	public static function enableWorkShiftOption(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->enableShifts();
		}
	}

	public static function isDayStartCheckInEnabled(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->isDayStartCheckInEnabled();
	}

	public static function isDayStartCheckInEnabledInSettings(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->isDayStartCheckInEnabledInSettings();
	}

	public static function canChangeDayStartCheckIn(): bool
	{
		return self::isAvailable() && (new SettingsProvider())->canChangeDayStartCheckIn();
	}

	public static function enableDayStartCheckIn(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->enableDayStartCheckIn();
		}
	}

	public static function disableDayStartCheckIn(): void
	{
		if (self::isAvailable())
		{
			(new SettingsProvider())->disableDayStartCheckIn();
		}
	}

	/**
	 * Whether the reader may see the work time of that employee. Work time tracking answers that, and it
	 * resolves the answer once per request, so asking it again within one costs nothing.
	 */
	protected static function canReadWorkTime(int $userId): bool
	{
		return (new RecordProvider())->canReadWorktime($userId);
	}

	/**
	 * The breaks work time tracking pairs out of the records handed to it. The right above is resolved behind
	 * this reading rather than before it, so entering it at all is what a day pays the resolve of an entire
	 * org structure for - and that is why the step is named here instead of being written inline.
	 *
	 * @param array<int, Record> $records
	 * @return array<int, array{from: int, to: ?int}>
	 */
	protected static function readPauseIntervals(int $userId, array $records, int $dateFrom, int $dateTo): array
	{
		return (new RecordProvider())->getPauseIntervalsOfRecords($userId, $records, $dateFrom, $dateTo);
	}

	private static function isDayBeingLivedThrough(int $timestamp, int $timezoneOffset): bool
	{
		return date('Y-m-d', $timestamp + $timezoneOffset) === date('Y-m-d', time() + $timezoneOffset);
	}

	/**
	 * Every record that began inside the window, in the order work time tracking hands them in - the shifts
	 * the day is made of.
	 *
	 * @return array<int, Record>
	 */
	private static function getWindowRecords(int $userId, int $windowStart, int $windowEnd): array
	{
		$recordCollection = (new RecordProvider())->getRecords(
			new ListParams(
				pager: new Pager(limit: 100, offset: 0),
				filter: new Filter(
					userId: $userId,
					dateFrom: $windowStart,
					dateTo: $windowEnd - 1,
				),
			)
		);

		$records = [];
		foreach ($recordCollection as $record)
		{
			$records[] = $record;
		}

		return $records;
	}

	/**
	 * The record open right now, as a set of one: the closing of the day being lived through is read off it
	 * and off nothing else. It may have begun outside the window - a day opened last night and closed this
	 * morning is found by its closing alone - and a shift of this morning already stopped says nothing about
	 * the one still running.
	 *
	 * @return array<int, Record>
	 */
	private static function getCurrentRecords(int $userId): array
	{
		$record = (new RecordProvider())->getCurrentRecord($userId);

		return $record === null ? [] : [$record];
	}

	/**
	 * The two sets as one, each record once: the current record usually began inside the window and is
	 * already among its records, and handed in twice it would pair its breaks twice.
	 *
	 * @param array<int, Record> $records
	 * @param array<int, Record> $more
	 * @return array<int, Record>
	 */
	private static function mergeRecords(array $records, array $more): array
	{
		$byId = [];
		foreach ([...$records, ...$more] as $record)
		{
			$byId[$record->id] = $record;
		}

		return array_values($byId);
	}

	/**
	 * The earliest of the records the window holds: a day of several shifts begins where the first of them
	 * did, however the records came ordered.
	 *
	 * @param array<int, Record> $records
	 */
	private static function findStartTimestamp(array $records, int $windowStart, int $windowEnd): ?int
	{
		$start = null;
		foreach ($records as $record)
		{
			$startTime = $record->startTime;
			if ($startTime !== null && $startTime >= $windowStart && $startTime < $windowEnd)
			{
				$start = $start === null ? $startTime : min($start, $startTime);
			}
		}

		return $start;
	}

	/**
	 * The latest of the closings the window holds: a day of several shifts ends where the last of them was
	 * stopped, however the records came ordered - the same way it begins with the earliest of them. A shift
	 * still running closes nothing, so a day whose last record is open is closed by no record at all.
	 *
	 * @param array<int, Record> $records
	 */
	private static function findCloseTimestamp(array $records, int $windowStart, int $windowEnd): ?int
	{
		$close = null;
		foreach ($records as $record)
		{
			$endTime = $record->endTime;
			if (
				$record->state->status->value === 'closed'
				&& $endTime !== null
				&& $endTime >= $windowStart
				&& $endTime < $windowEnd
			)
			{
				$close = $close === null ? $endTime : max($close, $endTime);
			}
		}

		return $close;
	}

	/**
	 * The breaks of the day, paired out of the records its window holds.
	 *
	 * A break the records left open ends nowhere while the day is being lived through: it is running right
	 * now, and everything measured against it grows together with it. A day already past has nothing running
	 * in it - a record nothing ever closed would otherwise go on covering that day with one break reaching
	 * towards now, and no later answer would ever shorten it - so there the break ends where the day does.
	 *
	 * The breaks of a day being lived through are read off the current record as well: a night shift begun
	 * yesterday is not among the records of this window, yet the break it stands in right now is this day's
	 * silence to sign - the same reason the closing of such a day is read off the current record. The
	 * pairing itself cuts what it answers back to the window, so the part of a break taken before midnight
	 * stays with the day the shift began in. A caller that has already read the current record hands it in
	 * through $currentRecords; left null, the reading is made here.
	 *
	 * A window of no records is answered before the reading rather than by it: an employee who never opened
	 * a work day has no log to pair, and the pairing would resolve the right to his work time - an entire org
	 * structure - only to find that out. The day of every employee outside work time tracking asks for its
	 * breaks all the same, so the empty window is the common case and not the odd one.
	 *
	 * @param array<int, Record> $windowRecords
	 * @param ?array<int, Record> $currentRecords
	 * @return array<int, array{from: int, to: ?int}>
	 */
	private static function foldWindowPauses(
		int $userId,
		array $windowRecords,
		int $windowStart,
		int $windowEnd,
		bool $isBeingLivedThrough,
		?array $currentRecords = null
	): array
	{
		if ($isBeingLivedThrough)
		{
			// The latest record joins the day while it still belongs to it: a shift running right now, or
			// one already stopped whose closing lies inside this window - the same record findCloseTimestamp
			// closes the day by, and the closing and the breaks must come off one shift. A record stopped
			// on another day is no part of this one however recently it was written.
			$windowRecords = self::mergeRecords(
				$windowRecords,
				array_filter(
					$currentRecords ?? self::getCurrentRecords($userId),
					static fn(Record $record): bool => (
						$record->state->status->value !== 'closed'
						|| (
							$record->endTime !== null
							&& $record->endTime >= $windowStart
							&& $record->endTime < $windowEnd
						)
					),
				),
			);
		}

		if ($windowRecords === [])
		{
			return [];
		}

		$pauses = static::readPauseIntervals(
			$userId,
			$windowRecords,
			$windowStart,
			$windowEnd - 1,
		);

		if ($isBeingLivedThrough)
		{
			return $pauses;
		}

		return array_map(
			static fn(array $pause): array => $pause['to'] === null
				? ['from' => $pause['from'], 'to' => max($pause['from'], $windowEnd)]
				: $pause,
			$pauses,
		);
	}

	/**
	 * The local day of the moment, in server time. Open to the day answer as well, which weighs the age of
	 * that day against the cleanup by the very edge the work day is read from: counted a second time over
	 * there, the two would part by however far the employee lives from the clock of whoever named the date.
	 *
	 * USER_TIMEZONE is the offset of the employee relative to the SERVER, not to UTC - the very contract
	 * the write path accepts points under (PointAcceptanceService::boundsOfLocalDay) - so both midnights
	 * are built by the server's own date()/strtotime(). The upper bound is the next midnight rather than
	 * + 86400: a daylight saving day lasts 82800 or 90000 seconds.
	 *
	 * @return array{0: int, 1: int} start and end of the local day the timestamp falls into, end excluded.
	 */
	public static function getDayWindow(int $timestamp, int $timezoneOffset): array
	{
		$localDate = date('Y-m-d', $timestamp + $timezoneOffset);
		$windowStart = (int)strtotime($localDate . ' 00:00:00') - $timezoneOffset;
		$windowEnd = (int)strtotime($localDate . ' 00:00:00 +1 day') - $timezoneOffset;

		return [$windowStart, $windowEnd];
	}

	private static function getTimeManUser(): ?\CTimeManUser
	{
		if (self::$timeManUser === null)
		{
			self::$timeManUser = \CTimeManUser::instance();
		}

		return self::$timeManUser;
	}

	private static function isTimeManIntegrationEnabled(): bool
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();

		$value = OptionProvider::getInstance()
			->getOption($userId, Option::TIMEMAN_INTEGRATION_ENABLED)
			?->getValue()
		;

		return !$value || $value === 'Y';
	}

	public static function isWorkTimeToolAvailable(): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		return (ToolsManager::getInstance())->checkAvailabilityByToolId('worktime');
	}
}
