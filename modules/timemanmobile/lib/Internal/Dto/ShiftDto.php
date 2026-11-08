<?php

namespace Bitrix\TimemanMobile\Internal\Dto;

use Bitrix\Mobile\Dto\Dto;

class ShiftDto extends Dto
{
	public string $start;
	public string $stop;
	public int $startTimestamp;
	public int $stopTimestamp;
	public string $scheduleType;
}
