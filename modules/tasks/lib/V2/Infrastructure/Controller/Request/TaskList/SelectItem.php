<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Request\TaskList;

use Bitrix\Main\Validation\Rule\InArray;

class SelectItem
{
	public const ALLOWED_PARAMETERS = [
		FieldsDictionary::ID,
		FieldsDictionary::TITLE,
		FieldsDictionary::ACTIVITY_DATE,
		FieldsDictionary::DEADLINE,
		FieldsDictionary::CREATOR,
		FieldsDictionary::RESPONSIBLE,
		FieldsDictionary::GROUP,
		FieldsDictionary::CREATED_DATE,
		FieldsDictionary::CHANGED_DATE,
		FieldsDictionary::CLOSED_DATE,
		FieldsDictionary::TIME_ESTIMATE,
		FieldsDictionary::ALLOW_TIME_TRACKING,
		FieldsDictionary::MARK,
		FieldsDictionary::ALLOW_CHANGE_DEADLINE,
		FieldsDictionary::TIME_SPENT_IN_LOGS,
		FieldsDictionary::START_DATE_PLAN,
		FieldsDictionary::END_DATE_PLAN,
		FieldsDictionary::UF_CRM_TASK_LEAD,
		FieldsDictionary::UF_CRM_TASK_CONTACT,
		FieldsDictionary::UF_CRM_TASK_COMPANY,
		FieldsDictionary::UF_CRM_TASK_DEAL,
		FieldsDictionary::UF_CRM_TASK,
		FieldsDictionary::FLOW,
		FieldsDictionary::STATUS,
		FieldsDictionary::COMPLETE,
		FieldsDictionary::TAGS,
		FieldsDictionary::LINKS,
	];

	public function __construct(
		#[InArray(self::ALLOWED_PARAMETERS)]
		public readonly string $field,
	)
	{
	}
}
