<?php

namespace Bitrix\TimemanMobile\Internal\Dto;

use Bitrix\Mobile\Dto\Dto;

class WorkdayIntentScheduleDto extends Dto
{
	public string $mode = '';
	public string $periodKey = '';
	public int $targetStartTimestamp = 0;
	public int $periodEndTimestamp = 0;
	public int $targetShiftStartTimestamp = 0;
	public int $targetShiftStopTimestamp = 0;
	public int $retryIntervalSeconds = 0;
	public int $lastShownTimestamp = 0;
	public int $maxShows = 0;
	public int $maxDismisses = 0;
	public int $showCount = 0;
	public int $dismissCount = 0;
	public int $suppressedUntilTimestamp = 0;
	public bool $hasTargetAction = false;
	public bool $shiftStartable = true;
}
