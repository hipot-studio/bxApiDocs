<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum;

enum BizprocDesignerPullEvent: string
{
	case AiDraftUpdated = 'bizprocdesigner_ai_draft_updated';
	case TemplateLockLost = 'bizprocdesigner_template_lock_lost';

	/**
	 * EVENT-01. The discussions of a template have changed. Addressed by the tag of the template
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\Pull\TemplateNoteWatch}) and not by a user:
	 * everybody who holds the scheme open is told.
	 */
	case TemplateNoteUpdated = 'bizprocdesigner_template_note_updated';
}
