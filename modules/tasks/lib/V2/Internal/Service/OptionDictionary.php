<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

enum OptionDictionary: string
{
	case AhaResponsibleMany = 'aha_responsible_many';
	case AhaTaskSettingsMessage = 'aha_task_settings_message';
	case AhaTaskChat = 'aha_task_chat';
	case AhaTaskImportantMessages = 'aha_task_important_messages';
	case AhaAuditorsInCompactForm = 'aha_auditors_compact_form';
	case AhaRequiredResultCreator = 'aha_required_result_creator';
	case AhaRequiredResultResponsible = 'aha_required_result_responsible';
	case AhaResultFromMessage = 'aha_result_from_message';
	case AhaStartTimeTracking = 'aha_start_time_tracking';
}
