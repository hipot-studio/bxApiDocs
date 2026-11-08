<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\Schedule as LegacySchedule;
use Bitrix\Timeman\Model\Schedule\ScheduleCollection as LegacyScheduleCollection;
use Bitrix\Timeman\Provider\Schedule\ScheduleProvider;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\Service\Worktime\Action\ShiftWithDate;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\ScheduleCollection;
use Bitrix\Timeman\V2\Internal\Entity\Shift\Shift;
use Bitrix\Timeman\V2\Internal\Repository\RecordIntentStateRepository;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ScheduleRepository;
use Bitrix\Timeman\V2\Internal\Repository\ShiftRepository;

/**
 * Resolves the welcome-box display state for a user (ALG-01): mode, periodKey, show window and the
 * effective maxShows for the current period. Idempotently stamps first-use and resets the period
 * row on a period change through {@see RecordIntentStateRepository}.
 *
 * The returned envelope mirrors DTO-01 plus the internal-only canShowNow derivative.
 */
class RecordIntentService
{
	public const MODE_DATA_COLLECTION = 'dataCollection';
	public const MODE_SMART = 'smart';
	public const MODE_SHIFT = 'shift';

	public const BASIS_HISTORY = 'history';
	public const BASIS_SCHEDULE = 'schedule';
	public const BASIS_SHIFT = 'shift';
	public const BASIS_DATA_COLLECTION = 'dataCollection';
	public const BASIS_DEFAULT = 'default';
	public const BASIS_PERIOD = 'period';

	public const MAX_SHOWS = 4;
	public const WEEKEND_MAX_SHOWS = 1;
	public const MAX_DISMISSES = 4;
	public const RETRY_INTERVAL_SECONDS = 3600;
	public const SHIFT_LEAD_SECONDS = 1200;
	public const HISTORY_LEAD_SECONDS = 1200;
	public const DATA_COLLECTION_START = 18000; // 05:00
	public const FALLBACK_START = 32400; // 09:00
	public const HISTORY_DAYS = 7;

	private const SECONDS_PER_DAY = 86400;

	private readonly TimeHelper $timeHelper;
	private readonly ScheduleProvider $legacyScheduleProvider;

	public function __construct(
		private readonly RecordIntentStateRepository $stateRepository,
		private readonly RecordRepository $recordRepository,
		private readonly ScheduleRepository $scheduleRepository,
		private readonly ShiftRepository $shiftRepository,
		?ScheduleProvider $legacyScheduleProvider = null,
	)
	{
		$this->timeHelper = TimeHelper::getInstance();
		$this->legacyScheduleProvider = $legacyScheduleProvider
			?? DependencyManager::getInstance()->getScheduleProvider();
	}

	/**
	 * Builds the full intent envelope for the user, persisting first-use and period resets as needed.
	 * targetStartBasis is one of BASIS_* and names the source of the returned targetStartTimestamp, which is
	 * persisted by the first resolve of the period: BASIS_PERIOD when the sources have changed since and no
	 * longer give that target.
	 *
	 * @return array{
	 *     mode: string,
	 *     periodKey: string,
	 *     targetStartTimestamp: int,
	 *     periodEndTimestamp: int,
	 *     targetShiftStartTimestamp: int,
	 *     targetShiftStopTimestamp: int,
	 *     retryIntervalSeconds: int,
	 *     lastShownTimestamp: int,
	 *     maxShows: int,
	 *     maxDismisses: int,
	 *     showCount: int,
	 *     dismissCount: int,
	 *     suppressedUntilTimestamp: int,
	 *     hasTargetAction: bool,
	 *     shiftStartable: bool,
	 *     targetStartBasis: string,
	 *     canShowNow: bool
	 * }
	 */
	public function resolveSchedule(int $userId): array
	{
		$nowUtc = $this->timeHelper->getUtcNowTimestamp();

		$state = $this->stateRepository->getByUserId($userId);
		if ($state === null)
		{
			$initResult = $this->stateRepository->initFirstUse($userId, $nowUtc);
			$initState = $initResult->getData();
			$state = (is_array($initState) && $initState !== [])
				? $initState
				: $this->buildDefaultState($userId, $nowUtc);
		}

		$schedules = $this->scheduleRepository->findByUserId($userId);
		$legacySchedules = $this->loadLegacySchedulesWithShifts($schedules);

		$maxShows = self::MAX_SHOWS;
		// Interval of the target shift the box refers to (DTO-01 / addAbsence); null = no target shift.
		$targetShift = null;

		if ($this->hasShifted($schedules))
		{
			$shift = $this->resolveShift($userId);
			if ($shift === null || $shift->start === null || $shift->stop === null)
			{
				return $this->buildEnvelope(
					self::MODE_SHIFT,
					$state,
					$maxShows,
					periodKey: (string)$state['PERIOD_KEY'],
					targetStartAt: 0,
					periodEndAt: 0,
					nowUtc: $nowUtc,
					targetStartBasis: self::BASIS_SHIFT,
					targetShift: null,
					canShowNowOverride: false,
				);
			}

			$shiftStartTs = $shift->start->getTimestamp();
			$shiftEndTs = $shift->stop->getTimestamp();
			$timeZone = $this->timeHelper->getUserDateTimeZone($userId);

			$periodKey = 'S' . $shift->id . '-' . $this->localDate($shiftStartTs, $timeZone);
			$targetStartAt = $shiftStartTs - self::SHIFT_LEAD_SECONDS;
			$periodEndAt = $shiftEndTs;
			$mode = self::MODE_SHIFT;
			$targetShift = ['start' => $shiftStartTs, 'stop' => $shiftEndTs];

			$state = $this->maybeResetPeriod($userId, $state, $periodKey, $targetStartAt, $periodEndAt, $nowUtc);

			// For a shift schedule shows are forbidden once the actual shift is over, regardless of counters.
			$shiftIsOver = $nowUtc >= $shiftEndTs;

			// Surface the PERSISTED period the server actually enforces: if no reset happened (period still
			// active), the saved key/window stay in effect, so the client's registerShow guard matches.
			return $this->buildEnvelope(
				$mode,
				$state,
				$maxShows,
				periodKey: (string)$state['PERIOD_KEY'],
				targetStartAt: (int)$state['TARGET_START_AT'],
				periodEndAt: (int)$state['PERIOD_END_AT'],
				nowUtc: $nowUtc,
				targetStartBasis: $this->basisOfPersistedTarget($state, $targetStartAt, self::BASIS_SHIFT),
				targetShift: $targetShift,
				canShowNowOverride: $shiftIsOver ? false : null,
			);
		}

		$timeZone = $this->timeHelper->getUserDateTimeZone($userId);
		$periodKey = 'D' . $this->localDate($nowUtc, $timeZone);
		$periodEndAt = $this->startOfNextLocalDayUtc($nowUtc, $timeZone);

		$historyMedian = $this->medianLocalStartSeconds($userId, $nowUtc);
		$scheduleStartSeconds = $this->scheduleStartSeconds($legacySchedules);

		// Set by the history branch itself: the choice of the target formula below stays bound to that source
		// whatever the order of the sources.
		$fromHistory = false;
		if ($historyMedian !== null)
		{
			$mode = self::MODE_SMART;
			$targetStartBasis = self::BASIS_HISTORY;
			$fromHistory = true;
			// Not earlier than the start of the current local day.
			$startSeconds = max(0, $historyMedian - self::HISTORY_LEAD_SECONDS);
		}
		elseif ($scheduleStartSeconds !== null)
		{
			$mode = self::MODE_SMART;
			$targetStartBasis = self::BASIS_SCHEDULE;
			$startSeconds = $scheduleStartSeconds;
		}
		elseif ($nowUtc < (int)$state['FIRST_USE_AT'] + self::HISTORY_DAYS * self::SECONDS_PER_DAY)
		{
			$mode = self::MODE_DATA_COLLECTION;
			$targetStartBasis = self::BASIS_DATA_COLLECTION;
			$startSeconds = self::DATA_COLLECTION_START;
		}
		else
		{
			$mode = self::MODE_SMART;
			$targetStartBasis = self::BASIS_DEFAULT;
			$startSeconds = self::FALLBACK_START;
		}

		// The history start is set on the wall clock of the current local day: on a DST transition day it
		// neither slips into the previous day nor shifts by an hour.
		$targetStartAt = $fromHistory
			? $this->localDayTimeUtc($nowUtc, $timeZone, $startSeconds)
			: $periodEndAt - self::SECONDS_PER_DAY + $startSeconds;

		// Show window gate for the fixed working-day branch. For flextime/free schedules it stays true
		// and the window is bounded only by the local day (behaviour unchanged).
		$shiftStartable = true;

		if ($this->hasFixed($schedules))
		{
			$weekDay = $this->isoDayOfWeek($nowUtc, $timeZone);
			$fixedSchedule = $this->findFixedSchedule($legacySchedules);
			if ($fixedSchedule !== null && $fixedSchedule->getShiftByWeekDay($weekDay) === null)
			{
				// True day off of the fixed schedule: no startable shift, "Start" opens an out-of-plan day.
				$maxShows = self::WEEKEND_MAX_SHOWS;
			}
			elseif ($fixedSchedule !== null)
			{
				// Working day: the target shift is the very one Record.start would open. A null relevant
				// shift means now is outside [start - half; end + half] (endedByTime built in) → not startable.
				$relevantShift = $this->resolveStartableShift($userId, $legacySchedules);
				$shiftStartable = $relevantShift !== null;
				if ($relevantShift !== null)
				{
					$targetShift = [
						'start' => $relevantShift->getDateTimeStart()->getTimestamp(),
						'stop' => $relevantShift->getDateTimeEnd()->getTimestamp(),
					];
				}
			}
		}

		$state = $this->maybeResetPeriod($userId, $state, $periodKey, $targetStartAt, $periodEndAt, $nowUtc);

		// Surface the PERSISTED period the server actually enforces (see shift branch above).
		return $this->buildEnvelope(
			$mode,
			$state,
			$maxShows,
			periodKey: (string)$state['PERIOD_KEY'],
			targetStartAt: (int)$state['TARGET_START_AT'],
			periodEndAt: (int)$state['PERIOD_END_AT'],
			nowUtc: $nowUtc,
			targetStartBasis: $this->basisOfPersistedTarget($state, $targetStartAt, $targetStartBasis),
			targetShift: $targetShift,
			shiftStartable: $shiftStartable,
			canShowNowOverride: $shiftStartable ? null : false,
		);
	}

	/**
	 * UTC timestamp of the next local midnight of the user: the scope a verdict about the user's
	 * current local day may cover, whatever period the show window currently belongs to.
	 */
	public function getNextLocalDayStart(int $userId): int
	{
		return $this->startOfNextLocalDayUtc(
			$this->timeHelper->getUtcNowTimestamp(),
			$this->timeHelper->getUserDateTimeZone($userId),
		);
	}

	/**
	 * Resets the period row only when the computed key differs AND the stored period has ended.
	 * Until the stored period end the saved key stays in effect (timezone changes mid-period do not
	 * recreate the period).
	 */
	private function maybeResetPeriod(
		int $userId,
		array $state,
		string $periodKey,
		int $targetStartAt,
		int $periodEndAt,
		int $nowUtc,
	): array
	{
		if ((string)$state['PERIOD_KEY'] !== $periodKey && $nowUtc >= (int)$state['PERIOD_END_AT'])
		{
			$resetResult = $this->stateRepository->resetPeriod($userId, $periodKey, $targetStartAt, $periodEndAt);
			$resetState = $resetResult->getData();
			if (is_array($resetState) && $resetState !== [])
			{
				return $resetState;
			}
		}

		return $state;
	}

	/**
	 * The basis of the persisted target start the envelope reports. The target is kept until the period ends,
	 * so once the history or the schedule changes within the period, the source of this resolve gives another
	 * target and no longer explains the persisted one.
	 */
	private function basisOfPersistedTarget(array $state, int $targetStartAt, string $targetStartBasis): string
	{
		return (int)$state['TARGET_START_AT'] === $targetStartAt ? $targetStartBasis : self::BASIS_PERIOD;
	}

	/**
	 * @param array{start: int, stop: int}|null $targetShift Interval of the target shift (DTO-01); null = none.
	 */
	private function buildEnvelope(
		string $mode,
		array $state,
		int $maxShows,
		string $periodKey,
		int $targetStartAt,
		int $periodEndAt,
		int $nowUtc,
		string $targetStartBasis,
		?array $targetShift = null,
		bool $shiftStartable = true,
		?bool $canShowNowOverride = null,
	): array
	{
		$showCount = (int)$state['SHOW_COUNT'];
		$dismissCount = (int)$state['DISMISS_COUNT'];
		$suppressedUntil = (int)$state['SUPPRESSED_UNTIL'];
		$hasTargetAction = (string)$state['HAS_TARGET_ACTION'] === 'Y';
		$lastShownAt = (int)$state['LAST_SHOWN_AT'];

		$stateTargetStartAt = (int)$state['TARGET_START_AT'];
		$statePeriodEndAt = (int)$state['PERIOD_END_AT'];

		$canShowNow =
			$nowUtc >= $stateTargetStartAt
			&& $nowUtc < $statePeriodEndAt
			&& $showCount < $maxShows
			&& $dismissCount < self::MAX_DISMISSES
			&& $nowUtc >= $suppressedUntil
			// Server-side retry interval, unified across devices (AC-6): the next show is not earlier than
			// RETRY_INTERVAL_SECONDS after the previous one. LAST_SHOWN_AT = 0 does not block the first show.
			&& $nowUtc >= $lastShownAt + self::RETRY_INTERVAL_SECONDS
			&& !$hasTargetAction;

		if ($canShowNowOverride !== null)
		{
			$canShowNow = $canShowNowOverride && $canShowNow;
		}

		return [
			'mode' => $mode,
			'periodKey' => $periodKey,
			'targetStartTimestamp' => $targetStartAt,
			'periodEndTimestamp' => $periodEndAt,
			'targetShiftStartTimestamp' => (int)($targetShift['start'] ?? 0),
			'targetShiftStopTimestamp' => (int)($targetShift['stop'] ?? 0),
			'retryIntervalSeconds' => self::RETRY_INTERVAL_SECONDS,
			'lastShownTimestamp' => $lastShownAt,
			'maxShows' => $maxShows,
			'maxDismisses' => self::MAX_DISMISSES,
			'showCount' => $showCount,
			'dismissCount' => $dismissCount,
			'suppressedUntilTimestamp' => $suppressedUntil,
			'hasTargetAction' => $hasTargetAction,
			'shiftStartable' => $shiftStartable,
			'targetStartBasis' => $targetStartBasis,
			'canShowNow' => $canShowNow,
		];
	}

	private function hasShifted(ScheduleCollection $schedules): bool
	{
		return $schedules->find(
			static fn (Schedule $schedule): bool => $schedule->type === Schedule::TYPE_SHIFT,
		) !== null;
	}

	private function hasFixed(ScheduleCollection $schedules): bool
	{
		return $schedules->find(
			static fn (Schedule $schedule): bool => $schedule->type === Schedule::TYPE_FIXED,
		) !== null;
	}

	/**
	 * Nearest still-running shift (soonest start whose end is in the future), else the next shift.
	 */
	private function resolveShift(int $userId): ?Shift
	{
		$nowUtc = $this->timeHelper->getUtcNowTimestamp();
		$nearest = $this->shiftRepository->findNearestShifts($userId);

		$actual = null;
		foreach ($nearest as $shift)
		{
			if ($shift->start === null || $shift->stop === null)
			{
				continue;
			}
			if ($shift->stop->getTimestamp() <= $nowUtc)
			{
				continue;
			}
			if ($actual === null || $shift->start->getTimestamp() < $actual->start->getTimestamp())
			{
				$actual = $shift;
			}
		}

		if ($actual !== null)
		{
			return $actual;
		}

		$schedules = $this->scheduleRepository->findByUserId($userId);

		return $this->shiftRepository->findNextByUserId($userId, $schedules);
	}

	/**
	 * The shift Record.start would open right now (fixed working day): the relevant shift in the user's
	 * local timescale. Null when now is outside the startable window [start - half; end + half]
	 * (endedByTime built in), i.e. the shift can no longer be started.
	 */
	private function resolveStartableShift(int $userId, LegacyScheduleCollection $legacySchedules): ?ShiftWithDate
	{
		return DependencyManager::getInstance()
			->buildShiftsManager($userId, $legacySchedules)
			->buildRelevantShiftWithDate(new \DateTime('now', $this->timeHelper->getUserDateTimeZone($userId)));
	}

	/**
	 * Median of local start seconds across the user's closed records over HISTORY_DAYS: the values are
	 * ordered by the time of day and the middle one is taken, for an even count the earlier of the two
	 * middle values. Null when there are no closed records in the window.
	 */
	private function medianLocalStartSeconds(int $userId, int $nowUtc): ?int
	{
		$fromTs = $nowUtc - self::HISTORY_DAYS * self::SECONDS_PER_DAY;
		$rows = $this->recordRepository->getClosedStartTimestampsByUserAndRange($userId, $fromTs, $nowUtc);
		if (empty($rows))
		{
			return null;
		}

		$startSeconds = [];
		foreach ($rows as $row)
		{
			$startTs = (int)$row['RECORDED_START_TIMESTAMP'];
			if ($startTs <= 0)
			{
				continue;
			}
			$startSeconds[] = $this->timeHelper->convertUtcTimestampToDaySeconds($startTs, (int)$row['START_OFFSET']);
		}

		if ($startSeconds === [])
		{
			return null;
		}

		// The rows come in chronological order, not by the time of day.
		sort($startSeconds);

		return $startSeconds[intdiv(count($startSeconds) - 1, 2)];
	}

	/**
	 * WORK_TIME_START of the first shift of the user's first non-flextime schedule (local day seconds),
	 * or null when no active shift is available.
	 */
	private function scheduleStartSeconds(LegacyScheduleCollection $legacySchedules): ?int
	{
		foreach ($legacySchedules as $schedule)
		{
			$shifts = $schedule->obtainActiveShifts();
			foreach ($shifts as $shift)
			{
				return (int)$shift->getWorkTimeStart();
			}
		}

		return null;
	}

	private function findFixedSchedule(LegacyScheduleCollection $legacySchedules): ?LegacySchedule
	{
		foreach ($legacySchedules as $schedule)
		{
			if ($schedule->isFixed())
			{
				return $schedule;
			}
		}

		return null;
	}

	private function loadLegacySchedulesWithShifts(ScheduleCollection $schedules): LegacyScheduleCollection
	{
		return $this->legacyScheduleProvider->findSchedulesCollectionByIdsWithShifts($schedules->getIds());
	}

	/**
	 * Fallback state used only when the first-use init did not return a row (e.g. DB unavailable):
	 * keeps resolveSchedule total so the read path never throws on a missing state row.
	 */
	private function buildDefaultState(int $userId, int $nowUtc): array
	{
		return [
			'ID' => 0,
			'USER_ID' => $userId,
			'FIRST_USE_AT' => $nowUtc,
			'PERIOD_KEY' => '',
			'TARGET_START_AT' => 0,
			'PERIOD_END_AT' => 0,
			'SHOW_COUNT' => 0,
			'DISMISS_COUNT' => 0,
			'SUPPRESSED_UNTIL' => 0,
			'HAS_TARGET_ACTION' => 'N',
			'LAST_REGISTERED_AT' => 0,
			'LAST_SHOWN_AT' => 0,
		];
	}

	private function localDate(int $timestamp, \DateTimeZone $timeZone): string
	{
		return (new \DateTime('@' . $timestamp))->setTimezone($timeZone)->format('Ymd');
	}

	private function isoDayOfWeek(int $timestamp, \DateTimeZone $timeZone): int
	{
		return (int)(new \DateTime('@' . $timestamp))->setTimezone($timeZone)->format('N');
	}

	/**
	 * UTC timestamp of the start (00:00 local) of the local day following the one containing $nowUtc.
	 * Uses the user's real IANA zone so the day boundary is correct across DST transitions
	 * (the local day may be 23h or 25h, not a fixed 24h).
	 */
	private function startOfNextLocalDayUtc(int $nowUtc, \DateTimeZone $timeZone): int
	{
		return (new \DateTime('@' . $nowUtc))
			->setTimezone($timeZone)
			->setTime(0, 0, 0)
			->modify('+1 day')
			->getTimestamp();
	}

	/**
	 * UTC timestamp of the wall-clock time $daySeconds on the local day containing $nowUtc. $daySeconds is
	 * the time of day in seconds (H * 3600 + M * 60 + S), not the time elapsed since midnight: it is set on
	 * the clock of the user's real IANA zone, so it stays the same wall-clock time on a DST transition day
	 * (the local day may be 23h or 25h, not a fixed 24h).
	 */
	private function localDayTimeUtc(int $nowUtc, \DateTimeZone $timeZone, int $daySeconds): int
	{
		$localDateTime = (new \DateTime('@' . $nowUtc))->setTimezone($timeZone);
		$this->timeHelper->setTimeFromSeconds($localDateTime, $daySeconds);

		return $localDateTime->getTimestamp();
	}
}
