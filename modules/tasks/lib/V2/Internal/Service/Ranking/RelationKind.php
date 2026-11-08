<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Ranking;

enum RelationKind: string
{
	case Parent = 'parent';
	case Related = 'related';
	case Subtask = 'subtask';
	case Gantt = 'gantt';
}
