<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository\Mapper;

use Bitrix\Timeman\Model\Worktime\Record\EO_WorktimeRecord;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordCollection as LegacyWorktimeRecordCollection;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\Shift\Shift;
use Bitrix\Timeman\V2\Internal\Entity\Record\Record;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordCollection;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordState;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordStatus;

class RecordMapper
{
	public function mapToCollectionFromOrm(
		LegacyWorktimeRecordCollection $records,
		bool $includeSchedule = false,
		bool $includeShift = false,
	): RecordCollection
	{
		$collection = new RecordCollection();

		foreach ($records as $record)
		{
			$collection->add($this->mapFromOrm($record, $includeSchedule, $includeShift));
		}

		return $collection;
	}

	public function mapFromOrm(
		EO_WorktimeRecord $record,
		bool $includeSchedule = false,
		bool $includeShift = false,
		?RecordState $state = null,
	): Record
	{
		$scheduleEntity = null;
		if ($includeSchedule)
		{
			$schedule = $record->obtainSchedule();
			if ($schedule)
			{
				$scheduleEntity = new Schedule(
					id: (int)$schedule->getId(),
					name: (string)$schedule->getName(),
					type: ScheduleMapper::normalizeType((string)$schedule->getScheduleType()),
				);
			}
		}

		$shiftEntity = null;
		if ($includeShift)
		{
			$shift = $record->obtainShift();
			if ($shift)
			{
				$shiftEntity = new Shift(
					id: (int)$shift->getId(),
					name: (string)$shift->getName(),
					workTimeStart: (int)$shift->getWorkTimeStart(),
					workTimeEnd: (int)$shift->getWorkTimeEnd(),
				);
			}
		}

		if ($state === null)
		{
			$state = RecordState::fromStatusOnly(RecordStatus::fromRecord($record));
		}

		return new Record(
			id: (int)$record->getId(),
			userId: (int)$record->getUserId(),
			startTime: (int)$record->getRecordedStartTimestamp(),
			endTime: ((int)$record->getRecordedStopTimestamp() > 0) ? (int)$record->getRecordedStopTimestamp() : null,
			duration: (int)$record->getRecordedDuration(),
			breakLength: (int)$record->getRecordedBreakLength(),
			state: $state,
			isApproved: (bool)$record->isApproved(),
			shift: $shiftEntity,
			schedule: $scheduleEntity,
		);
	}
}
