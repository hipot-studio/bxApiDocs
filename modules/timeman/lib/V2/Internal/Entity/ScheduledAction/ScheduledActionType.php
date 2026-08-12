<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\ScheduledAction;

enum ScheduledActionType: string
{
	case FullReportAiGenerate = 'full_report_ai_generate';
}
