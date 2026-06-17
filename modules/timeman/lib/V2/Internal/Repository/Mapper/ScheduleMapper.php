<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository\Mapper;

use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;

final class ScheduleMapper
{
	public static function normalizeType(string $type): string
	{
		return match (strtoupper($type))
		{
			ScheduleTable::SCHEDULE_TYPE_FIXED => Schedule::TYPE_FIXED,
			ScheduleTable::SCHEDULE_TYPE_SHIFT => Schedule::TYPE_SHIFT,
			ScheduleTable::SCHEDULE_TYPE_FLEXTIME => Schedule::TYPE_FLEXTIME,
			default => strtolower($type),
		};
	}
}
