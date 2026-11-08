<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Feedback;

enum FeedbackSource: string
{
	case TASKS = 'tasks';
	case PROJECTS = 'projects';
}
