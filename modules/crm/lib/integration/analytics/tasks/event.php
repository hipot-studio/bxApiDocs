<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Analytics\Tasks;

enum Event: string
{
	case TaskComplete = 'task_complete';
}
