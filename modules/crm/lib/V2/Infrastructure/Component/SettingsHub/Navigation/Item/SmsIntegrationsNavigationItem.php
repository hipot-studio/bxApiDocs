<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class SmsIntegrationsNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $hasConfigurableSenders,
		private bool $isMessageServiceAvailable,
		private bool $isMessageServiceAdmin,
		private ?Uri $connectionsUrl,
	)
	{
	}

	public function id(): string
	{
		return 'sms-integrations';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->hasConfigurableSenders;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isMessageServiceAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_SMS_INTEGRATIONS_MODULE'),
					'CRM_SETTINGS_HUB_NAVIGATION_SMS_INTEGRATIONS_MODULE',
				),
			);
		}

		if (!$this->isMessageServiceAdmin || !$permissions->messageSender()->canConfigureChannels())
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_SMS_INTEGRATIONS_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_SMS_INTEGRATIONS_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->connectionsUrl === null
			? null
			: (clone $this->connectionsUrl)->addParams(['tab' => 'sms'])
		;
	}
}
