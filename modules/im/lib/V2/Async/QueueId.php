<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Async;

enum QueueId: string
{
	case DepartmentTreeSync = 'im_department_tree_sync';
}
