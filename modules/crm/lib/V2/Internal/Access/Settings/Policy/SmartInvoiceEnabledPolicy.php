<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class SmartInvoiceEnabledPolicy implements SettingAccessPolicyInterface
{
	public function check(SettingInterface $setting, UserPermissions $userPermissions): Result
	{
		$allowed = InvoiceSettings::getCurrent()->isSmartInvoiceEnabled();
		if ($allowed)
		{
			return new Result();
		}

		return (new Result())->addError(
			new Error(
				Loc::getMessage('CRM_SETTINGS_POLICY_SMART_INVOICE_ENABLED_DENIED'),
				'CRM_SETTINGS_POLICY_SMART_INVOICE_ENABLED_DENIED',
			),
		);
	}
}
