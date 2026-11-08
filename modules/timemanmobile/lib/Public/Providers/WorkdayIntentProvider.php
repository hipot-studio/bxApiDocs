<?php

namespace Bitrix\TimemanMobile\Public\Providers;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Public\Command\RecordIntent\RegisterOutcomeCommand;
use Bitrix\Timeman\V2\Public\Command\RecordIntent\RegisterShowCommand;
use Bitrix\Timeman\V2\Public\Command\RecordIntent\SuppressPeriodCommand;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;
use Bitrix\Timeman\V2\Public\Dto\Schedule\Schedule;
use Bitrix\Timeman\V2\Public\Dto\Shift\Shift;
use Bitrix\Timeman\V2\Public\Dto\Shift\ShiftCollection;
use Bitrix\Timeman\V2\Public\Provider\RecordIntentProvider;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;
use Bitrix\TimemanMobile\Internal\Dto\ShiftDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentBoxDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentDto;
use Bitrix\TimemanMobile\Internal\Dto\WorkdayIntentScheduleDto;
use Bitrix\TimemanMobile\Internal\Integration\Bizproc\Providers\DayPlanBotProvider;
use Bitrix\TimemanMobile\Internal\Integration\Intranet\UserAbsence;
use Bitrix\TimemanMobile\Internal\Integration\Stafftrack\Provider\CheckInSettingsProvider;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithAiFeature;

class WorkdayIntentProvider
{
	private const INTENT_WORKDAY = 'WORKDAY';
	private const INTENT_WEEKEND = 'WEEKEND';
	private const ABSENCE_INTENT_IDS = ['LEAVEUNPAYED', 'VACATION', 'LEAVESICK'];
	private const SUPPRESS_REASON_DAY_OFF = 'dayOff';
	private const SUPPRESS_REASON_ABSENCE = 'absence';

	private ?int $userId;
	private ServiceLocator $locator;
	private ?RecordIntentProvider $recordIntentProvider = null;
	private ?ShiftCollection $nearestShiftCollection = null;
	private ?\Throwable $nearestShiftReadError = null;
	private ?array $absenceTypesOnUserDay = null;

	public function __construct()
	{
		$this->userId = CurrentUser::get()->getId();
		$this->locator = ServiceLocator::getInstance();
	}

	private function getRecordIntentProvider(): RecordIntentProvider
	{
		return $this->recordIntentProvider ??= new RecordIntentProvider();
	}

	/**
	 * @return bool
	 */
	public function canUseTimeman(): bool
	{
		return $this->locator->get(RecordProvider::class)->canUseTimeman($this->userId);
	}

	/**
	 * @return bool
	 */
	public function canManageWorktimeOnMobile(): bool
	{
		return $this->locator->get(RecordProvider::class)->canManageWorktimeOnMobile($this->userId);
	}

	/**
	 * @return bool
	 */
	public function canStartWorkday(): bool
	{
		$timemanUser = \CTimeManUser::instance();
		$workTimeState = $timemanUser->state();

		return $workTimeState === 'CLOSED' && $timemanUser->openAction() === 'OPEN';
	}

	/**
	 * @return bool
	 */
	public function canOpenWorkdayIntentBox(): bool
	{
		return
			$this->hasIntentBaseAdmission()
			&& $this->canStartWorkday()
		;
	}

	/**
	 * @return WorkdayIntentBoxDto
	 */
	public function getWorkdayIntentBoxData(): WorkdayIntentBoxDto
	{
		$admissionFailure = null;
		if (!$this->hasIntentBaseAdmission($admissionFailure))
		{
			return WorkdayIntentBoxDto::make([
				'canOpen' => false,
				'suppressReason' => $admissionFailure,
			]);
		}

		$recordIntentSchedule = $this->getRecordIntentProvider()->getSchedule($this->userId);
		$schedule = WorkdayIntentScheduleDto::make($recordIntentSchedule->toArray());

		$canOpen = $this->canStartWorkday() && $this->canShowNow($recordIntentSchedule);
		if (!$canOpen)
		{
			return WorkdayIntentBoxDto::make([
				'canOpen' => false,
				'schedule' => $schedule,
			]);
		}

		// Daily conditions come after the show window: they read schedules and absences from the
		// database, while every check above is cheap and the client asks on every foreground return.
		try
		{
			$suppressReason = $this->resolveDailySuppressReason();
		}
		catch (\Throwable)
		{
			// fail-open: a broken timeman/intranet must not hide the box for the whole company
			$suppressReason = null;
		}

		if ($suppressReason !== null)
		{
			// A refusal suppresses the show window on the timeman side, so the next forced reads stop
			// at the cheap gate instead of re-running the daily conditions all day long.
			$suppressed = $this->suppressPeriod($recordIntentSchedule);

			return WorkdayIntentBoxDto::make([
				'canOpen' => false,
				'suppressReason' => $suppressReason,
				// Without the write there is nothing to reschedule by: no schedule postpones the
				// client to the next local day instead of an hourly retry over the same conditions.
				'schedule' => $suppressed === null
					? null
					: WorkdayIntentScheduleDto::make($suppressed->toArray()),
			]);
		}

		$intentList = $this->getIntents();

		return WorkdayIntentBoxDto::make([
			'canOpen' => true,
			'intentList' => $intentList,
			'shifts' => $this->getNearestShifts(),
			'preselectedIntentId' => $this->resolvePreselectedIntentId($intentList),
			'chatIdWithAiAdvice' => $this->getChatIdWithAiAdvice(),
			'isDayStartCheckInEnabled' => (
				CheckInSettingsProvider::isCheckInEnabled()
				&& $this->locator->get(SettingsProvider::class)->isDayStartCheckInEnabled()
			),
			'schedule' => $schedule,
		]);
	}

	private function getChatIdWithAiAdvice(): int
	{
		if (!Feature::isEnabled(WorkReportsWithAiFeature::class))
		{
			return 0;
		}

		return DayPlanBotProvider::getBotId();
	}

	/**
	 * @param RecordIntentSchedule $schedule
	 * @return bool
	 */
	private function canShowNow(RecordIntentSchedule $schedule): bool
	{
		$now = time();

		return
			$now >= $schedule->targetStartTimestamp
			&& $now < $schedule->periodEndTimestamp
			&& $schedule->showCount < $schedule->maxShows
			&& $schedule->dismissCount < $schedule->maxDismisses
			&& $now >= $schedule->suppressedUntilTimestamp
			&& $schedule->hasTargetAction === false
			// Server-side retry interval, unified across devices (AC-6): the next show is not earlier than
			// retryIntervalSeconds after the previous one. lastShownTimestamp = 0 does not block the first show.
			&& $now >= $schedule->lastShownTimestamp + $schedule->retryIntervalSeconds
			&& $schedule->shiftStartable
		;
	}

	/**
	 * @return string|null reason the box is not applicable today, null when the daily conditions pass
	 */
	private function resolveDailySuppressReason(): ?string
	{
		if ($this->isDayOffByScheduleForUserDay())
		{
			return self::SUPPRESS_REASON_DAY_OFF;
		}

		if ($this->hasAnyAbsenceOnUserDay())
		{
			return self::SUPPRESS_REASON_ABSENCE;
		}

		return null;
	}

	/**
	 * Closes the show window on the timeman side and returns the resulting schedule, or null when
	 * the window did not end up closed. A failed write is not an error of the box itself: the refusal
	 * stands either way.
	 *
	 * @param RecordIntentSchedule $schedule
	 * @return RecordIntentSchedule|null
	 */
	private function suppressPeriod(RecordIntentSchedule $schedule): ?RecordIntentSchedule
	{
		try
		{
			// The schedule goes into the command so the answer is built from it: it is resolved right
			// above in this same request, and without it the command resolves the whole schedule anew.
			$result = (new SuppressPeriodCommand($this->userId, $schedule->periodKey, $schedule))->run();
		}
		catch (\Throwable)
		{
			return null;
		}

		$suppressed = $result->isSuccess() ? ($result->getData()['schedule'] ?? null) : null;
		if (!$suppressed instanceof RecordIntentSchedule)
		{
			return null;
		}

		// The answered schedule is the proof of the write, not the success flag itself: timemanmobile
		// ships independently of timeman, so a bound that is not ahead of now (window still open) has
		// to reach the same branch as a failed write.
		return $suppressed->suppressedUntilTimestamp > time() ? $suppressed : null;
	}

	/**
	 * Base mobile admission for the intent scenario. Mirrors the gate in getWorkdayIntentBoxData:
	 * only users who may participate are allowed to touch the timeman intent-state (which lazily
	 * stamps firstUseAt on first resolve). Mobile eligibility is the consumer layer's responsibility.
	 *
	 * @return bool
	 */
	private function hasIntentBaseAdmission(?string &$failureReason = null): bool
	{
		// Guard against an empty current user before reaching the int-typed timeman signatures
		// (RecordProvider::canUseTimeman/canManageWorktimeOnMobile, RecordIntentProvider::getSchedule).
		if (empty($this->userId))
		{
			return false;
		}

		try
		{
			$shiftsEnabled = $this->locator->get(SettingsProvider::class)->isShiftsEnabled();
		}
		catch (\Throwable)
		{
			$shiftsEnabled = false;
		}

		if (!$shiftsEnabled)
		{
			$failureReason = WorkdayIntentBoxDto::SUPPRESS_REASON_SHIFTS_DISABLED;

			return false;
		}

		if (!$this->canUseTimeman() || !$this->canManageWorktimeOnMobile())
		{
			return false;
		}

		return true;
	}

	/**
	 * @param string $periodKey
	 * @return Result
	 */
	public function registerShow(string $periodKey): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		return $this->runRegisterShowCommand($periodKey);
	}

	/**
	 * @param string $periodKey
	 * @param string $outcome
	 * @return Result
	 */
	public function registerOutcome(string $periodKey, string $outcome): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		return $this->runRegisterOutcomeCommand($periodKey, $outcome);
	}

	protected function runRegisterShowCommand(string $periodKey): Result
	{
		return (new RegisterShowCommand($this->userId, $periodKey))->run();
	}

	protected function runRegisterOutcomeCommand(string $periodKey, string $outcome): Result
	{
		return (new RegisterOutcomeCommand($this->userId, $periodKey, $outcome))->run();
	}

	/**
	 * @return array
	 */
	public function getIntents(): array
	{
		$baseIntents = [self::INTENT_WORKDAY, self::INTENT_WEEKEND];
		$preferredAbsences = self::ABSENCE_INTENT_IDS;
		$availableAbsences = UserAbsence::getAbsenceTypes();
		$absenceKeys = array_values(array_intersect($preferredAbsences, $availableAbsences));

		return array_map(
			fn (string $id): WorkdayIntentDto => WorkdayIntentDto::make([
				'id' => $id,
				'label' => Loc::getMessage("TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_INTENT_{$id}"),
			]),
			array_merge($baseIntents, $absenceKeys),
		);
	}

	/**
	 * @return ShiftDto[]
	 */
	public function getNearestShifts(): array
	{
		try
		{
			$shiftCollection = $this->getNearestShiftCollection();
		}
		catch (\Throwable)
		{
			// fail-open: an unreadable schedule degrades to the same synthetic shift a free schedule
			// produces, instead of failing the whole action after the daily conditions already passed
			return [$this->buildFlextimeShift()];
		}

		if ($shiftCollection->isEmpty())
		{
			return [$this->buildFlextimeShift()];
		}

		/** @var ShiftDto[] $shifts */
		$shifts = $shiftCollection->map(
			function (Shift $shift): ShiftDto {
				$shiftArray = $shift->toArray();

				return ShiftDto::make([
					...$shiftArray,
					'scheduleType' => $shiftArray['schedule']['type'],
					'startTimestamp' => DateTime::createFromUserTime($shiftArray['start'])->getTimestamp(),
					'stopTimestamp' => DateTime::createFromUserTime($shiftArray['stop'])->getTimestamp(),
				]);
			},
		);
		/** @var Schedule|null $schedule */
		$schedule = $shiftCollection->getIterator()->current()?->schedule;

		// No schedule on the first shift: fall back to a synthetic flextime shift, same as the
		// non-shifted / non-fixed (free schedule) branches below.
		if ($schedule === null)
		{
			return [$this->buildFlextimeShift()];
		}

		if ($schedule->isShifted())
		{
			return $this->filterActualShifts($shifts) ?: [$this->buildFlextimeShift()];
		}

		if ($schedule->isFixed())
		{
			if (!empty($actualShifts = $this->filterActualShifts($shifts)))
			{
				return [$actualShifts[0]];
			}

			return [$this->buildFlextimeShift()];
		}

		return [$this->buildFlextimeShift()];
	}

	/**
	 * The failure is memoized as well as the collection: the daily conditions and the payload share
	 * one read, so a broken timeman is asked once and answers both of them the same way.
	 *
	 * @return ShiftCollection
	 * @throws \Throwable the memoized error of the shift read
	 */
	private function getNearestShiftCollection(): ShiftCollection
	{
		if ($this->nearestShiftReadError !== null)
		{
			throw $this->nearestShiftReadError;
		}

		if ($this->nearestShiftCollection === null)
		{
			try
			{
				$this->nearestShiftCollection =
					$this->locator->get(RecordProvider::class)->getNearestShifts($this->userId)
				;
			}
			catch (\Throwable $error)
			{
				$this->nearestShiftReadError = $error;

				throw $error;
			}
		}

		return $this->nearestShiftCollection;
	}

	/**
	 * @param WorkdayIntentDto[] $intentList
	 * @return string
	 */
	private function resolvePreselectedIntentId(array $intentList): string
	{
		try
		{
			$intentIds = array_map(
				static fn (WorkdayIntentDto $intent): string => $intent->id,
				$intentList,
			);

			return $this->findAbsenceIntentId($intentIds) ?? $this->resolveScheduleIntentId();
		}
		catch (\Throwable)
		{
			return self::INTENT_WORKDAY;
		}
	}

	/**
	 * @param string[] $intentIds
	 * @return string|null
	 */
	private function findAbsenceIntentId(array $intentIds): ?string
	{
		$absenceIntentIds = array_intersect(self::ABSENCE_INTENT_IDS, $intentIds);
		if (empty($absenceIntentIds))
		{
			return null;
		}

		foreach ($this->getAbsenceTypesOnUserDay() as $absenceType)
		{
			if (in_array($absenceType, $absenceIntentIds, true))
			{
				return $absenceType;
			}
		}

		return null;
	}

	/**
	 * Any absence, without the ABSENCE_INTENT_IDS whitelist: the whitelist belongs to the
	 * preselect (only three types are offered in the UI), while the show gate hides the box
	 * on every kind of absence. An unavailable intranet/iblock reads as "no absence", which
	 * degrades towards showing the box.
	 *
	 * @return bool
	 */
	private function hasAnyAbsenceOnUserDay(): bool
	{
		return !empty($this->getAbsenceTypesOnUserDay());
	}

	/**
	 * @return string[]
	 */
	private function getAbsenceTypesOnUserDay(): array
	{
		if ($this->absenceTypesOnUserDay === null)
		{
			$userNow = TimeHelper::getInstance()->getUserDateTimeNow($this->userId);
			$day = new Date($userNow->format('Y-m-d'), 'Y-m-d');

			$this->absenceTypesOnUserDay = UserAbsence::getAbsenceTypesByDay($this->userId, $day);
		}

		return $this->absenceTypesOnUserDay;
	}

	/**
	 * @return string
	 */
	private function resolveScheduleIntentId(): string
	{
		return $this->isDayOffByScheduleForUserDay()
			? self::INTENT_WEEKEND
			: self::INTENT_WORKDAY
		;
	}

	/**
	 * Built on getNearestShiftCollection() and not on the public getNearestShifts(): the latter
	 * substitutes a synthetic flextime shift for an empty collection and filters shifts by the
	 * now..now+24h window, so it never reports a day off.
	 *
	 * @return bool
	 */
	private function isDayOffByScheduleForUserDay(): bool
	{
		$shiftCollection = $this->getNearestShiftCollection();

		if ($shiftCollection->isEmpty())
		{
			// no schedules assigned means "can work any day"; a fixed/shifted
			// schedule without shifts in the nearest window means a day off
			$schedules = DependencyManager::getInstance()
				->getScheduleProvider()
				->findSchedulesByUserId($this->userId)
			;

			return !empty($schedules);
		}

		/** @var ?Schedule $schedule */
		$schedule = $shiftCollection->getIterator()->current()?->schedule;
		if ($schedule === null || (!$schedule->isFixed() && !$schedule->isShifted()))
		{
			return false;
		}

		return !$this->hasShiftStartingOnCurrentUserDay($shiftCollection);
	}

	/**
	 * Checks the calendar day in the user timezone, unlike filterActualShifts()
	 * which uses a sliding now..now+24h window. Shift->start carries the real
	 * instant of the shift rendered in UTC (ShiftRepository::createDateTimeUtc),
	 * so its own calendar date is not the employee's one: it has to be converted
	 * into their timezone first, the way the schedule owner does it
	 * (RecordIntentService::localDate).
	 *
	 * @param ShiftCollection $shiftCollection
	 * @return bool
	 */
	private function hasShiftStartingOnCurrentUserDay(ShiftCollection $shiftCollection): bool
	{
		$timeHelper = TimeHelper::getInstance();
		$timeZone = $timeHelper->getUserDateTimeZone((int)$this->userId);
		$userDay = $timeHelper->getUserDateTimeNow($this->userId)->format('Y-m-d');

		$shiftOnDay = $shiftCollection->find(
			fn (Shift $shift): bool =>
				$shift->start !== null
				&& $this->localDate($shift->start->getTimestamp(), $timeZone) === $userDay,
		);

		return $shiftOnDay !== null;
	}

	/**
	 * Calendar date of an absolute instant in the employee timezone. The same zone bounds the
	 * suppression written on a refusal (RecordIntentService::getNextLocalDayStart), so the daily
	 * conditions and that bound speak about one and the same calendar day of the employee.
	 *
	 * @param int $timestamp absolute point in time = UTC Unix-seconds
	 * @param \DateTimeZone $timeZone
	 * @return string
	 */
	private function localDate(int $timestamp, \DateTimeZone $timeZone): string
	{
		return (new \DateTime('@' . $timestamp))->setTimezone($timeZone)->format('Y-m-d');
	}

	/**
	 * @return ShiftDto
	 */
	private function buildFlextimeShift(): ShiftDto
	{
		$timeZone = TimeHelper::getInstance()->getUserDateTimeZone((int)$this->userId);
		$dayStart = (new \DateTime('now', $timeZone))->setTime(0, 0);
		$dayEnd = (clone $dayStart)->add(new \DateInterval('P1D'));

		return ShiftDto::make([
			'start' => $dayStart->format(DateTime::getFormat()),
			'stop' => $dayEnd->format(DateTime::getFormat()),
			'startTimestamp' => $dayStart->getTimestamp(),
			'stopTimestamp' => $dayEnd->getTimestamp(),
			'scheduleType' => 'flextime',
		]);
	}

	/**
	 * @param ShiftDto[] $shifts
	 * @return ShiftDto[]
	 */
	private function filterActualShifts(array $shifts): array
	{
		$now = time();
		$startIndex = null;

		foreach ($shifts as $index => $shift)
		{
			if ($shift->startTimestamp <= $now && $now < $shift->stopTimestamp)
			{
				$startIndex = $index;
				break;
			}

			if ($shift->startTimestamp > $now)
			{
				$startIndex = $index;
				break;
			}
		}

		if ($startIndex === null)
		{
			return [];
		}

		$result = [];
		$shiftsCount = count($shifts);

		for ($i = $startIndex; $i < $shiftsCount; $i++)
		{
			if ($shifts[$i]->startTimestamp >= $now + 86400)
			{
				break;
			}

			$result[] = $shifts[$i];
		}

		return $result;
	}

	/**
	 * Records an absence on the target startable shift from the timeman schedule (RecordIntentSchedule):
	 * the very shift "Start" would open. The shifts coming from the frontend (getNearestShifts) are no
	 * longer used for interval selection — after a shift ends they point to the next shift and diverge
	 * from the real start. When there is no target shift (true day off of a fixed schedule, or a free
	 * schedule with no shift) the absence is not written: "Day off" behaves like the existing WEEKEND
	 * UI choice — closes the popup without a record.
	 *
	 * @param string $absenceType
	 * @param array $shifts kept for transport compatibility; not used for interval selection
	 * @return Result
	 */
	public function addAbsence(string $absenceType, array $shifts): Result
	{
		if (!$this->hasIntentBaseAdmission())
		{
			return (new Result())->addError(new Error('Access denied', 'access_denied'));
		}

		$schedule = $this->getRecordIntentProvider()->getSchedule($this->userId);

		$result = new Result();

		// No target shift: no-op with a successful response (acts like the UI weekend choice).
		if ($schedule->targetShiftStartTimestamp <= 0 || $schedule->targetShiftStopTimestamp <= 0)
		{
			return $result->setData(['absences' => []]);
		}

		$absences = [
			[
				'from' => DateTime::createFromTimestamp($schedule->targetShiftStartTimestamp),
				'to' => DateTime::createFromTimestamp($schedule->targetShiftStopTimestamp),
				'description' => Loc::getMessage("TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_INTENT_{$absenceType}")
					?: Loc::getMessage('TIMEMANMOBILE_WORKDAY_INTENT_PROVIDER_ABSENCE_DESCRIPTION'),
				'type' => $absenceType,
			],
		];

		$resultAbsences = [];

		$addResults = $this->addUserAbsences($absences);
		foreach ($addResults as $addResult)
		{
			if (!$addResult->isSuccess())
			{
				$result->addErrors($addResult->getErrors());

				continue;
			}

			$resultAbsences[] = $addResult->getData()['absence'] ?? null;
		}

		return $result->setData(['absences' => array_filter($resultAbsences)]);
	}

	protected function addUserAbsences(array $absences): array
	{
		return UserAbsence::addAbsences($this->userId, $absences);
	}
}
