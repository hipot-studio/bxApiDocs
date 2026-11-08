<?php

namespace Bitrix\Tasks\Integration\Bizproc\Starter;

use Bitrix\Main\Config\Option;

final class TasksCreateTriggerFeature
{
	public static function isEnabled(): bool
	{
		return Option::get('tasks', 'bizproc_create_trigger_available', 'N') === 'Y';
	}
}
