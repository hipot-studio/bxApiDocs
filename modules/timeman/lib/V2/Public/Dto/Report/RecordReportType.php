<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Report;

final class RecordReportType
{
	public const REPORT = 'REPORT';
	public const AI_REPORT = 'AI_REPORT';
	public const ROBOT_REPORT = 'ROBOT_REPORT';
	public const AI_DAY_PLAN = 'AI_DAY_PLAN';
	public const ROBOT_DAY_PLAN = 'ROBOT_DAY_PLAN';

	public static function getDefault(): string
	{
		return self::REPORT;
	}

	public static function getValues(): array
	{
		return [
			self::REPORT,
			self::AI_REPORT,
			self::ROBOT_REPORT,
			self::AI_DAY_PLAN,
			self::ROBOT_DAY_PLAN,
		];
	}

	public static function getExtendedValues(): array
	{
		return [
			self::AI_REPORT,
			self::ROBOT_REPORT,
		];
	}

	public static function getPlanValues(): array
	{
		return [
			self::AI_DAY_PLAN,
			self::ROBOT_DAY_PLAN,
		];
	}

	public static function normalize(?string $type): string
	{
		$type = is_string($type) ? trim($type) : '';

		return in_array($type, self::getValues(), true) ? $type : self::getDefault();
	}
}
