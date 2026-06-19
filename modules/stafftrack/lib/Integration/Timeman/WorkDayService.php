<?php

namespace Bitrix\StaffTrack\Integration\Timeman;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\Timeman\Form\Schedule\ViolationForm;
use Bitrix\Timeman\Form\Worktime\WorktimeRecordForm;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;

class WorkDayService
{
	private const DEFAULT_WORK_DAY_LENGTH = 28800;
	private const DAY_LENGTH = 86400;
	private ?\CTimeManUser $timeManUser = null;

	/**
	 * @return void
	 * @throws LoaderException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function handleWorkDayAfterShiftStart(): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$this->timeManUser = \CTimeManUser::instance();

		if (!$this->timeManUser->isDayOpen())
		{
			$this->timeManUser->openDay(false, '', [
				'DEVICE' => ScheduleTable::ALLOWED_DEVICES_MOBILE,
			]);
		}
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public function isDayOpened(): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$this->timeManUser ??= \CTimeManUser::instance();

		return $this->timeManUser->isDayOpenedToday();
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	public function isDayExpired(): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		$this->timeManUser ??= \CTimeManUser::instance();

		return $this->timeManUser->isDayOpen() && $this->timeManUser->isDayExpired();
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function closeActiveDay(): bool
	{
		$currentInfo = $this->timeManUser->getCurrentInfo(true);

		if (!$currentInfo)
		{
			return false;
		}

		$record = $this->findCurrentRecord($currentInfo['ID']);

		if (!$record)
		{
			return false;
		}

		$recordForm = new WorktimeRecordForm($record);

		if ($record->obtainSchedule() === null)
		{
			return $this->closeWithDefaultLength($recordForm);
		}

		return match ($record->obtainSchedule()->getScheduleType())
		{
			ScheduleTable::SCHEDULE_TYPE_SHIFT,
			ScheduleTable::SCHEDULE_TYPE_FIXED => $this->closeFixedOrShiftDay($recordForm, $record),
			ScheduleTable::SCHEDULE_TYPE_FLEXTIME => $this->closeWithDefaultLength($recordForm),
		};
	}

	/**
	 * @param $id
	 * @return WorktimeRecord|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function findCurrentRecord($id): ?WorktimeRecord
	{
		return (new WorktimeRepository())->findByIdWith(
			$id,
			[
				'USER',
				'WORKTIME_EVENTS',
				'SCHEDULE',
				'SHIFT',
				'SCHEDULE.SCHEDULE_VIOLATION_RULES',
			]
		);
	}

	/**
	 * @param WorktimeRecordForm $recordForm
	 * @return bool
	 */
	private function closeWithDefaultLength(WorktimeRecordForm $recordForm): bool
	{
		$recordForm->recordedStopTimestamp = $recordForm->recordedStartTimestamp + self::DEFAULT_WORK_DAY_LENGTH;
		$endTime = ($recordForm->recordedStopTimestamp + $recordForm->startOffset) % self::DAY_LENGTH;

		return (bool)$this->timeManUser->closeDay($endTime, $this->getCloseReason());
	}

	/**
	 * @param WorktimeRecordForm $recordForm
	 * @param WorktimeRecord $record
	 * @return bool
	 */
	private function closeFixedOrShiftDay(WorktimeRecordForm $recordForm, WorktimeRecord $record): bool
	{
		$shift = $record->obtainShift();

		if ($shift === null)
		{
			return $this->closeWithDefaultLength($recordForm);
		}

		/** @var Schedule $schedule */
		$schedule = $record->obtainSchedule();
		$startTimestamp = $recordForm->recordedStartTimestamp;
		$workTimeEnd = $shift->getWorkTimeEnd();

		$violationForm = new ViolationForm($schedule->obtainScheduleViolationRules());
		if (!empty($violationForm->minDayDuration) && $violationForm->minDayDuration > 0)
		{
			$closeTimestamp = $startTimestamp + $violationForm->minDayDuration;

			if (
				!empty($violationForm->minExactEnd)
				&& $violationForm->minExactEnd > 0
				&& ($violationForm->minExactEnd < ($closeTimestamp % self::DAY_LENGTH))
			)
			{
				$recordForm->recordedStopTimestamp = $closeTimestamp;
			}
			else
			{
				$recordForm->recordedStopTimestamp = ($startTimestamp - ($startTimestamp % self::DAY_LENGTH)) + $workTimeEnd;
			}
		}
		else
		{
			$recordForm->recordedStopTimestamp = ($startTimestamp - ($startTimestamp % self::DAY_LENGTH)) + $workTimeEnd;
		}

		$endTime = ($recordForm->recordedStopTimestamp + $recordForm->startOffset) % self::DAY_LENGTH;

		return (bool)$this->timeManUser->closeDay($endTime, $this->getCloseReason());
	}

	private function getCloseReason(): string
	{
		return Loc::getMessage('STAFFTRACK_INTEGRATION_TIMEMAN_CLOSE_DAY_REASON');
	}

	/**
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function isAvailable(): bool
	{
		return Loader::includeModule('timeman');
	}

	/**
	 * @param $params
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onAfterTmDayStart($params): void
	{
		if (!isset($params['DATE_START'], $params['START_OFFSET'], $params['USER_ID'], $params['ID']))
		{
			return;
		}

		if (Loader::includeModule('stafftrackmobile') && (new CheckInFeature())->isEnabled())
		{
			(new self())->aggregatePreviousDayCheckIns((int)$params['USER_ID'], (int)$params['ID']);
		}
	}

	/**
	 * @param int $userId
	 * @return void
	 */
	private function aggregatePreviousDayCheckIns(int $userId, int $currentRecordId): void
	{
		if (!Loader::includeModule('timeman'))
		{
			return;
		}

		$previousRecord = \Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable::query()
			->setSelect(['RECORDED_START_TIMESTAMP', 'RECORDED_STOP_TIMESTAMP'])
			->where('USER_ID', $userId)
			->where('ID', '<', $currentRecordId)
			->where('RECORDED_STOP_TIMESTAMP', '>', 0)
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch();

		if (!$previousRecord)
		{
			return;
		}

		$startTime = DateTime::createFromTimestamp($previousRecord['RECORDED_START_TIMESTAMP']);
		$endTime = DateTime::createFromTimestamp($previousRecord['RECORDED_STOP_TIMESTAMP']);

		$repository = new \Bitrix\StaffTrack\Internal\Repository\CheckInRepository();
		$rows = $repository->getCheckInRowsForAggregation($userId, $startTime, $endTime);

		(new \Bitrix\StaffTrack\Public\Services\CheckInAggregator())->aggregate($userId, $rows);
	}
}
