<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\ScheduledAction;

enum ScheduledActionStatus: string
{
	case Pending = 'pending';
	case Processing = 'processing';
	case Done = 'done';
	case Failed = 'failed';
	case Canceled = 'canceled';
}
