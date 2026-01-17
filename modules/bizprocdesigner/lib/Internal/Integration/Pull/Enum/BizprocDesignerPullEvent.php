<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum;

enum BizprocDesignerPullEvent: string
{
	case AiDraftUpdated = 'bizprocdesigner_ai_draft_updated';
}