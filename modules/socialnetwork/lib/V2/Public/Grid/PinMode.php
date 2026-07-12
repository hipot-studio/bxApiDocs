<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Grid;

enum PinMode: string
{
	case Common = '';
	case UserGroups = 'user_groups';
	case TasksProject = 'tasks_project';
	case TasksScrum = 'tasks_scrum';

	public static function fromMode(string $mode): self
	{
		return match ($mode)
		{
			'', 'common' => self::Common,
			'user', 'user_groups' => self::UserGroups,
			'project', 'tasks_project' => self::TasksProject,
			'scrum', 'tasks_scrum' => self::TasksScrum,
			default => self::Common,
		};
	}
}
