<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\LayoutSettings;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;

/**
 * @internal
 */
final class SliderDisabledPolicy implements SettingAccessPolicyInterface
{
	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool
	{
		return !LayoutSettings::getCurrent()->isSliderEnabled();
	}
}
