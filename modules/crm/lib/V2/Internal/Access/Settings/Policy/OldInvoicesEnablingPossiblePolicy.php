<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;

final class OldInvoicesEnablingPossiblePolicy implements SettingAccessPolicyInterface
{
	public function isAllowed(SettingInterface $setting, UserPermissions $userPermissions): bool
	{
		return InvoiceSettings::getCurrent()->isOldInvoicesEnablingPossible();
	}
}
