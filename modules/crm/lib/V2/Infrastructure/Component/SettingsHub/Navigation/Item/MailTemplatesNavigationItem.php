<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\V2\Internal\Access\MailTemplate\MailTemplateEditorAccess;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class MailTemplatesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private MailTemplateEditorAccess $access)
	{
	}

	public function id(): string
	{
		return 'mail-templates';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->entityType()->canReadSomeItemsInCrm();
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->access->hasCrmAccess($permissions->getUserId()))
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_MAIL_TEMPLATES_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_MAIL_TEMPLATES_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/mailtemplate/');
	}
}
