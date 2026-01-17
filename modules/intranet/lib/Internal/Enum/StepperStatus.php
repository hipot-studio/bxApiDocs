<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Enum;

enum StepperStatus: string
{
	case Idle = 'idle';
	case Scheduled = 'scheduled';
	case Running = 'running';
	case Failed = 'failed';
	case Success = 'success';

	public function canStart(): bool
	{
		return $this === self::Idle;
	}
}
