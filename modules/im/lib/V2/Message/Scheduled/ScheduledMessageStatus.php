<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Scheduled;

enum ScheduledMessageStatus: string
{
	case Scheduled = 'scheduled';
	case Failed = 'failed';
}
