<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ReturnCustomerAutoGenerationDisabledPolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = !LeadSettings::getCurrent()->isAutoGenRcEnabled();
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_RETURN_CUSTOMER_AUTO_GENERATION_DISABLED_DENIED'),
				'CRM_SETTINGS_POLICY_RETURN_CUSTOMER_AUTO_GENERATION_DISABLED_DENIED',
			),
		);
	}
}
