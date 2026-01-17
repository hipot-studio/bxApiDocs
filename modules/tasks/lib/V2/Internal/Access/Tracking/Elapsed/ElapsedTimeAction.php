<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed;

enum ElapsedTimeAction: string
{
	case Update = 'elapsed_time_update';
	case Delete = 'elapsed_time_delete';
}
