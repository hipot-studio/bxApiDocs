<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params\FullReport;

enum FieldsEnum: string
{
	case Id = 'id';
	case TimestampX = 'timestampX';
	case Active = 'active';
	case UserId = 'userId';
	case ReportDate = 'reportDate';
	case DateFrom = 'dateFrom';
	case DateTo = 'dateTo';
	case Tasks = 'tasks';
	case Events = 'events';
	case Files = 'files';
	case Report = 'report';
	case Plans = 'plans';
	case Mark = 'mark';
	case Approve = 'approve';
	case ApproveDate = 'approveDate';
	case Approver = 'approver';
	case ForumTopicId = 'forumTopicId';

	public function toOrmField(): string
	{
		return match ($this) {
			self::Id => 'ID',
			self::TimestampX => 'TIMESTAMP_X',
			self::Active => 'ACTIVE',
			self::UserId => 'USER_ID',
			self::ReportDate => 'REPORT_DATE',
			self::DateFrom => 'DATE_FROM',
			self::DateTo => 'DATE_TO',
			self::Tasks => 'TASKS',
			self::Events => 'EVENTS',
			self::Files => 'FILES',
			self::Report => 'REPORT',
			self::Plans => 'PLANS',
			self::Mark => 'MARK',
			self::Approve => 'APPROVE',
			self::ApproveDate => 'APPROVE_DATE',
			self::Approver => 'APPROVER',
			self::ForumTopicId => 'FORUM_TOPIC_ID',
		};
	}

	public static function allowedForSelectList(): array
	{
		return [
			self::Id,
			self::TimestampX,
			self::Active,
			self::UserId,
			self::ReportDate,
			self::DateFrom,
			self::DateTo,
			self::Tasks,
			self::Events,
			self::Files,
			self::Report,
			self::Plans,
			self::Mark,
			self::Approve,
			self::ApproveDate,
			self::Approver,
			self::ForumTopicId,
		];
	}

	public static function allowedForSortList(): array
	{
		return [
			self::Id,
			self::TimestampX,
			self::UserId,
			self::ReportDate,
			self::DateFrom,
			self::DateTo,
			self::Active,
		];
	}

	public static function allowedForFilterList(): array
	{
		return self::allowedForSelectList();
	}
}
