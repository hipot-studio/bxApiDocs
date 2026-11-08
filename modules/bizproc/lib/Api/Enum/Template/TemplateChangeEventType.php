<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum TemplateChangeEventType: int // values are persisted in b_bp_workflow_template_change and cannot be renumbered
{
	case Publication = 1;
	case Creation = 2;
	case DraftSave = 3;
}
