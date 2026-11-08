<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\CRM;

use Bitrix\Main\Config\Option;

/**
 * Feature toggle for the "CRM in tasks" list additions: the CRM_ITEM filter field, inline and mass CRM
 * binding and the filter onboarding hint. Disabled by default; enable per portal with
 * \Bitrix\Main\Config\Option::set('tasks', CrmInTasksFeature::OPTION_NAME, 'Y').
 */
final class CrmInTasksFeature
{
	public const OPTION_NAME = 'crm_in_tasks_enabled';

	public static function isOn(): bool
	{
		return Option::get('tasks', self::OPTION_NAME, 'N') === 'Y';
	}
}
