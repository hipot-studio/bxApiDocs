<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;

/**
 * @internal
 */
interface SettingAccessPolicyInterface
{
	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool;
}
