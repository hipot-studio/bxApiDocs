<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup;

enum WorkgroupPinMode: string
{
	case Common = '';
	case UserGroups = 'user_groups';
	case TasksProject = 'tasks_project';
	case TasksScrum = 'tasks_scrum';
}
