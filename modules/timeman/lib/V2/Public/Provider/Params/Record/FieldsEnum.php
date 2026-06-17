<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Record;

enum FieldsEnum: string
{
	case Id = 'id';
	case UserId = 'userId';
	case RecordedStartTimestamp = 'recordedStartTimestamp';
	case RecordedStopTimestamp = 'recordedStopTimestamp';
	case ActualStartTimestamp = 'actualStartTimestamp';
	case ActualStopTimestamp = 'actualStopTimestamp';
	case CurrentStatus = 'currentStatus';
	case Duration = 'duration';
	case RecordedDuration = 'recordedDuration';
	case ScheduleId = 'scheduleId';
	case ShiftId = 'shiftId';
	case Approved = 'approved';
	case TimestampX = 'timestampX';
	case Active = 'active';
	case Paused = 'paused';
	case DateStart = 'dateStart';
	case DateFinish = 'dateFinish';

	public function toOrmField(): string
	{
		return match ($this) {
			self::Id => 'ID',
			self::UserId => 'USER_ID',
			self::RecordedStartTimestamp => 'RECORDED_START_TIMESTAMP',
			self::RecordedStopTimestamp => 'RECORDED_STOP_TIMESTAMP',
			self::ActualStartTimestamp => 'ACTUAL_START_TIMESTAMP',
			self::ActualStopTimestamp => 'ACTUAL_STOP_TIMESTAMP',
			self::CurrentStatus => 'CURRENT_STATUS',
			self::Duration => 'DURATION',
			self::RecordedDuration => 'RECORDED_DURATION',
			self::ScheduleId => 'SCHEDULE_ID',
			self::ShiftId => 'SHIFT_ID',
			self::Approved => 'APPROVED',
			self::TimestampX => 'TIMESTAMP_X',
			self::Active => 'ACTIVE',
			self::Paused => 'PAUSED',
			self::DateStart => 'DATE_START',
			self::DateFinish => 'DATE_FINISH',
		};
	}

	public static function allowedForSelectList(): array
	{
		return [
			self::Id,
			self::UserId,
			self::RecordedStartTimestamp,
			self::RecordedStopTimestamp,
			self::ActualStartTimestamp,
			self::ActualStopTimestamp,
			self::CurrentStatus,
			self::Duration,
			self::RecordedDuration,
			self::ScheduleId,
			self::ShiftId,
			self::Approved,
			self::TimestampX,
			self::Active,
			self::Paused,
			self::DateStart,
			self::DateFinish,
		];
	}

	public static function allowedForSortList(): array
	{
		return [
			self::Id,
			self::UserId,
			self::RecordedStartTimestamp,
			self::RecordedStopTimestamp,
			self::CurrentStatus,
			self::Duration,
			self::RecordedDuration,
			self::TimestampX,
		];
	}

	public static function allowedForFilterList(): array
	{
		return self::allowedForSelectList();
	}
}
