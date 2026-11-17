<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter;

use Bitrix\Main\Config\Option;

/**
 * Feature toggle for the single operator between the member fields of the task list filter: with it on the
 * filled member fields in the "equal" mode are matched as OR instead of AND.
 *
 * An axis of its own on top of the field modes shipped by CrmInTasksFeature: the operator has nothing to act
 * on without them. Disabled by default; enable per portal with
 * \Bitrix\Main\Config\Option::set('tasks', MemberUnionFeature::OPTION_NAME, 'Y').
 */
class MemberUnionFeature
{
	public const OPTION_NAME = 'member_union_enabled';

	public static function isOn(): bool
	{
		return Option::get('tasks', self::OPTION_NAME, 'N') === 'Y';
	}
}
