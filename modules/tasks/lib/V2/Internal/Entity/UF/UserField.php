<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\UF;

class UserField
{
	public const TASK = 'TASKS_TASK';
	public const TEMPLATE = 'TASKS_TASK_TEMPLATE';

	public const TASK_ATTACHMENTS = 'UF_TASK_WEBDAV_FILES';
	public const CHECKLIST_ATTACHMENTS = 'UF_CHECKLIST_FILES';

	public const TASK_CRM = 'UF_CRM_TASK';

	public const TASK_MAIL = 'UF_MAIL_MESSAGE';

	public const TASK_RESULT = 'UF_RESULT_FILES';
	public const TASK_RESULT_PREVIEW = 'UF_RESULT_PREVIEW';

	public const TASK_SYSTEM_USER_FIELDS = [
		self::TASK_CRM,
		self::TASK_ATTACHMENTS,
		self::TASK_MAIL,
	];
}
