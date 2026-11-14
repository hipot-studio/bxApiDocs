<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Result;

/**
 * @internal
 */
interface SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result;
}
