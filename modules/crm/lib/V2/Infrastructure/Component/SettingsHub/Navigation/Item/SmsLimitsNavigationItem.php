<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class SmsLimitsNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $hasConfigurableSenders,
		private bool $isLimitationAvailable,
		private bool $isMessageServiceAvailable,
		private bool $isMessageServiceAdmin,
		private Uri $manageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'sms-limits';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin()
			&& $this->hasConfigurableSenders
			&& $this->isLimitationAvailable;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isMessageServiceAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_SMS_LIMITS_MODULE'),
					'CRM_SETTINGS_HUB_NAVIGATION_SMS_LIMITS_MODULE',
				),
			);
		}

		if (!$this->isMessageServiceAdmin)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_SMS_LIMITS_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_SMS_LIMITS_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return (clone $this->manageUrl)->addParams(['page' => 'limits']);
	}
}
