<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\ModuleManager;

/**
 * @internal
 */
final class Bitrix24ModuleNotInstalledPolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = !ModuleManager::isModuleInstalled('bitrix24');
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_BITRIX24_MODULE_NOT_INSTALLED_DENIED'),
				'CRM_SETTINGS_POLICY_BITRIX24_MODULE_NOT_INSTALLED_DENIED',
			),
		);
	}
}
