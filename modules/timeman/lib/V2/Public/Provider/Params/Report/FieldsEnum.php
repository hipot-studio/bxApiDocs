<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\Report;

enum FieldsEnum: string
{
	case Id = 'id';
	case TimestampX = 'timestampX';
	case EntryId = 'entryId';
	case UserId = 'userId';
	case Active = 'active';
	case ReportType = 'reportType';
	case Report = 'report';

	public function toOrmField(): string
	{
		return match ($this) {
			self::Id => 'ID',
			self::TimestampX => 'TIMESTAMP_X',
			self::EntryId => 'ENTRY_ID',
			self::UserId => 'USER_ID',
			self::Active => 'ACTIVE',
			self::ReportType => 'REPORT_TYPE',
			self::Report => 'REPORT',
		};
	}

	public static function allowedForSelectList(): array
	{
		return [
			self::Id,
			self::TimestampX,
			self::EntryId,
			self::UserId,
			self::Active,
			self::ReportType,
			self::Report,
		];
	}

	public static function allowedForSortList(): array
	{
		return [
			self::Id,
			self::TimestampX,
			self::EntryId,
			self::UserId,
		];
	}

	public static function allowedForFilterList(): array
	{
		return [
			self::Id,
			self::EntryId,
			self::UserId,
			self::Active,
			self::ReportType,
			self::Report,
		];
	}
}
