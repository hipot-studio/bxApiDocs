<?php

namespace Bitrix\Tasks\Util\UserField\Task;

use Bitrix\Tasks\Util\UserField\Task;

class Template extends Task
{
	public static function getEntityCode(): string
	{
		return 'TASKS_TASK_TEMPLATE';
	}
}
