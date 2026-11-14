<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class AiFillFieldsGlobalSettingEnabledPolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall);
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_AI_FILL_FIELDS_GLOBAL_SETTING_ENABLED_DENIED'),
				'CRM_SETTINGS_POLICY_AI_FILL_FIELDS_GLOBAL_SETTING_ENABLED_DENIED',
			),
		);
	}
}
