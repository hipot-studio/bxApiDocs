<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\CRM;

use Bitrix\Main\Config\Option;

/**
 * Feature toggle for the task list additions shipped together with "CRM in tasks":
 * - CRM scenarios: the CRM_ITEM filter field, inline and mass CRM binding, the filter onboarding hint;
 * - filter field modes: the "includes / does not include / not set" conditions and the tag AND/OR union;
 * - the "Accomplice" and "Auditor" grid columns.
 *
 * Disabled by default; enable per portal with
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
