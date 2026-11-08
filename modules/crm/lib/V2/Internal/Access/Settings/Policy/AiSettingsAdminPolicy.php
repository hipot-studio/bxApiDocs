<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Integration\Ai\SettingsAccessGateway;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;

/**
 * @internal
 */
final class AiSettingsAdminPolicy implements SettingAccessPolicyInterface
{
	public function __construct(private readonly SettingsAccessGateway $gateway)
	{
	}

	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool
	{
		return $this->gateway->isCurrentUserAdmin();
	}
}
