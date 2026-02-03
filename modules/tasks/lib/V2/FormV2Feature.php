<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2;

use Bitrix\Main\Config\Option;

class FormV2Feature
{
	public static function isOn(string $feature = '', int $groupId = null): bool
	{
		if ($feature === 'old_form')
		{
			return Option::get('tasks', 'tasks_old_form', 'N') === 'Y';
		}

		return true;
	}

	public static function turnOn(string $feature = ''): bool
	{
		return true;
	}

	public static function turnOff(string $feature = ''): bool
	{
		return false;
	}

	public static function getAllowedGroups(): array
	{
		$groups = Option::get('tasks', 'tasks_form_v2_groups', '');

		return array_map('intval', array_filter(explode(',', $groups)));
	}
}
