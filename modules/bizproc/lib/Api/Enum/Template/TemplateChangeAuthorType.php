<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

enum TemplateChangeAuthorType: int // values are persisted in b_bp_workflow_template_change and cannot be renumbered
{
	case Human = 1;
	case Ai = 2;
	case System = 3;
}
