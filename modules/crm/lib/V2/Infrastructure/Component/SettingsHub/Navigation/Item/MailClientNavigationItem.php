<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class MailClientNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isMailAvailable,
		private bool $isMailToolAvailable,
		private Uri $mailUrl,
	)
	{
	}

	public function id(): string
	{
		return 'mail-client';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->entityType()->canReadSomeItemsInCrm();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isMailAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_MAILCLIENT_MAIL_UNAVAILABLE'),
					'MAILCLIENT_MAIL_UNAVAILABLE',
				),
			);
		}

		if (!$this->isMailToolAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_MAILCLIENT_MAIL_TOOL_UNAVAILABLE'),
					'MAILCLIENT_MAIL_TOOL_UNAVAILABLE',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->mailUrl;
	}
}
