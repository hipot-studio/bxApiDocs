<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Model\Schedule\ScheduleCollection as LegacyScheduleCollection;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;
use Bitrix\Timeman\Provider\Schedule\ScheduleProvider;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\Service\Worktime\Action\ShiftWithDate;
use Bitrix\Timeman\Service\Worktime\Action\ShiftsManager;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\ScheduleCollection;
use Bitrix\Timeman\V2\Internal\Entity\Shift\Shift;
use Bitrix\Timeman\V2\Internal\Entity\Shift\ShiftCollection;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\ScheduleMapper;

final class ShiftRepository
{
	private readonly WorktimeRepository $worktimeRepository;
	private readonly ScheduleProvider $scheduleProvider;

	public function __construct(
		?WorktimeRepository $worktimeRepository = null,
		?ScheduleProvider $scheduleProvider = null,
	)
	{
		$this->worktimeRepository = $worktimeRepository
			?? DependencyManager::getInstance()->getWorktimeRepository();
		$this->scheduleProvider = $scheduleProvider
			?? DependencyManager::getInstance()->getScheduleProvider();
	}

	public function hasActiveShifts(ScheduleCollection $schedules): bool
	{
		return !$this->findBySchedules($schedules)->isEmpty();
	}

	public function findBySchedules(ScheduleCollection $schedules): ShiftCollection
	{
		$legacySchedules = $this->buildLegacySchedules($schedules);
		$shifts = new ShiftCollection();
		$addedShiftIds = [];

		foreach ($legacySchedules->obtainActiveShifts() as $legacyShift)
		{
			$shiftId = (int)$legacyShift->getId();
			if ($shiftId <= 0 || isset($addedShiftIds[$shiftId]))
			{
				continue;
			}

			$addedShiftIds[$shiftId] = true;

			$shifts->add(
				new Shift(
					id: $shiftId,
					name: (string)$legacyShift->getName(),
					workTimeStart: (int)$legacyShift->getWorkTimeStart(),
					workTimeEnd: (int)$legacyShift->getWorkTimeEnd(),
				),
			);
		}

		return $shifts;
	}

	public function findNextByUserId(int $userId, ScheduleCollection $schedules): ?Shift
	{
		if ($userId <= 0)
		{
			return null;
		}

		$legacySchedules = $this->buildLegacySchedules($schedules);
		if ($legacySchedules->count() === 0)
		{
			return null;
		}

		$shiftsManager = DependencyManager::getInstance()->buildShiftsManager($userId, $legacySchedules);
		$latestRecord = $this->worktimeRepository->findLatestRecord($userId);
		$currentShift = $this->buildRecordShiftWithDate($shiftsManager, $latestRecord);
		$userNow = TimeHelper::getInstance()->getUserDateTimeNow($userId);
		$nextShift = $shiftsManager->buildNextShiftWithDate($userNow, $currentShift);
		if ($nextShift === null)
		{
			return null;
		}

		$legacyShift = $nextShift->getShift();

		return new Shift(
			id: (int)$legacyShift->getId(),
			name: (string)$legacyShift->getName(),
			workTimeStart: (int)$legacyShift->getWorkTimeStart(),
			workTimeEnd: (int)$legacyShift->getWorkTimeEnd(),
			start: $this->createDateTimeUtc($nextShift->getDateTimeStart()->getTimestamp()),
			stop: $this->createDateTimeUtc($nextShift->getDateTimeEnd()->getTimestamp()),
			schedule: $this->buildScheduleDto($nextShift),
		);
	}

	public function findNearestShifts(int $userId): ShiftCollection
	{
		$shifts = new ShiftCollection();
		if ($userId <= 0)
		{
			return $shifts;
		}

		$legacySchedules = $this->scheduleProvider->findSchedulesCollectionByUserId($userId);
		if ($legacySchedules->count() === 0)
		{
			return $shifts;
		}

		$utcNow = TimeHelper::getInstance()->createDateTimeFromFormat(
			'U',
			(string)TimeHelper::getInstance()->getUtcNowTimestamp(),
		);
		$dayStart = (clone $utcNow)->setTime(0, 0);
		$nextDayStart = (clone $dayStart)->add(new \DateInterval('P2D'));

		if ($legacySchedules->hasFlextime())
		{
			$shifts->add(
				new Shift(
					id: 0,
					name: Schedule::TYPE_FLEXTIME,
					workTimeStart: 0,
					workTimeEnd: 86400,
					start: $this->createDateTimeUtc($dayStart->getTimestamp()),
					stop: $this->createDateTimeUtc($nextDayStart->getTimestamp()),
					schedule: new Schedule(
						id: 0,
						name: Schedule::TYPE_FLEXTIME,
						type: Schedule::TYPE_FLEXTIME,
					),
				),
			);

			return $shifts;
		}

		$shiftsManager = DependencyManager::getInstance()->buildShiftsManager($userId, $legacySchedules);
		$dayShifts = $shiftsManager->buildShiftWithDates($dayStart, $nextDayStart, false);

		foreach ($dayShifts as $dayShift)
		{
			$legacyShift = $dayShift->getShift();
			$shifts->add(
				new Shift(
					id: (int)$legacyShift->getId(),
					name: (string)$legacyShift->getName(),
					workTimeStart: (int)$legacyShift->getWorkTimeStart(),
					workTimeEnd: (int)$legacyShift->getWorkTimeEnd(),
					start: $this->createDateTimeUtc($dayShift->getDateTimeStart()->getTimestamp()),
					stop: $this->createDateTimeUtc($dayShift->getDateTimeEnd()->getTimestamp()),
					schedule: $this->buildScheduleDto($dayShift),
				),
			);
		}

		return $shifts;
	}

	private function buildLegacySchedules(ScheduleCollection $schedules): LegacyScheduleCollection
	{
		return $this->scheduleProvider->findSchedulesCollectionByIdsWithShifts($schedules->getIds());
	}

	private function buildRecordShiftWithDate(ShiftsManager $shiftsManager, ?WorktimeRecord $record): ?ShiftWithDate
	{
		if ($record === null)
		{
			return null;
		}

		$recordStartDateTime = $record->buildRecordedStartDateTime();
		if ($recordStartDateTime === null)
		{
			return null;
		}

		return $shiftsManager->buildRelevantRecordShiftWithDate(
			$recordStartDateTime,
			$record->obtainSchedule(),
			$record->obtainShift(),
		);
	}

	private function createDateTimeUtc(int $timestamp): DateTime
	{
		$phpDateTime = TimeHelper::getInstance()->createDateTimeFromFormat(
			'U',
			(string)$timestamp,
		);

		if ($phpDateTime === null)
		{
			return DateTime::createFromTimestamp($timestamp);
		}

		return new DateTime(
			$phpDateTime->format('Y-m-d H:i:s'),
			'Y-m-d H:i:s',
			$phpDateTime->getTimezone(),
		);
	}

	private function buildScheduleDto(ShiftWithDate $shiftWithDate): Schedule
	{
		$schedule = $shiftWithDate->getSchedule();

		return new Schedule(
			id: $schedule->getId(),
			name: $schedule->getName(),
			type: ScheduleMapper::normalizeType($schedule->getScheduleType()),
		);
	}
}
