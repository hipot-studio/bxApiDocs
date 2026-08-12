<?php

namespace Bitrix\Bizproc\Api\Enum\Template;

/**
 * Known workflow template section ids (b_bp_workflow_template_section.SECTION_ID).
 * A section id comes from trigger configurations and matches the nodes/{SECTION_ID}
 * system package directory.
 */
enum WorkflowTemplateSection: string
{
	case AiAgent = 'AI_AGENT';
}
