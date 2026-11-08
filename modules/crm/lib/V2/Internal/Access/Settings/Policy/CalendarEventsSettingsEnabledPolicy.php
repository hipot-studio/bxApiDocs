<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\ActivitySettings;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;

/**
 * @internal
 */
final class CalendarEventsSettingsEnabledPolicy implements SettingAccessPolicyInterface
{
	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool
	{
		return ActivitySettings::getValue(ActivitySettings::ENABLE_CALENDAR_EVENTS_SETTINGS);
	}
}
