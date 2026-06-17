<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum Category: string
{
	case TaskOperations = 'task_operations';
	case CommentsOperations = 'comments_operations';
	case TimeTracking = 'time_tracking';
	case Lead = 'lead';
	case Flows = 'flows';
}
