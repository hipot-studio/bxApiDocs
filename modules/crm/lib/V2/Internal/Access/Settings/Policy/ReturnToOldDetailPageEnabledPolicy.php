<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\ReturnToOldDetailPage;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class ReturnToOldDetailPageEnabledPolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		$allowed = Feature::enabled(ReturnToOldDetailPage::class);
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_RETURN_TO_OLD_DETAIL_PAGE_ENABLED_DENIED'),
				'CRM_SETTINGS_POLICY_RETURN_TO_OLD_DETAIL_PAGE_ENABLED_DENIED',
			),
		);
	}
}
