<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\TaskAnalytics\Enum;

use Bitrix\Tasks\V2\Internal\Entity\Trait\EnumValuesTrait;

enum SignificantHistoryField: string
{
	use EnumValuesTrait;

	case Status = 'STATUS';
	case Description = 'DESCRIPTION';
	case ChecklistItemCreate = 'CHECKLIST_ITEM_CREATE';
	case ChecklistItemCheck = 'CHECKLIST_ITEM_CHECK';
	case Stage = 'STAGE';
	case TimeSpentInLogs = 'TIME_SPENT_IN_LOGS';
	case Result = 'RESULT';
	case ResultEdit = 'RESULT_EDIT';
}
