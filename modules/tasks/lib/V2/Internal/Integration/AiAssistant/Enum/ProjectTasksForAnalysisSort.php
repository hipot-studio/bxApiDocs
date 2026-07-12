<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Enum;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum ProjectTasksForAnalysisSort: string
{
	use EnumValuesTrait;

	case RecentlyChanged = 'recently_changed';
	case Deadline = 'deadline';
}
