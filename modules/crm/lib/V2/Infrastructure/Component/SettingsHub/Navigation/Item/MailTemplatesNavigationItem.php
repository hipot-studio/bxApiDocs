<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\MailTemplate\MailTemplateEditorAccess;
use Bitrix\Main\Web\Uri;

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
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->access->hasCrmAccess($permissions->getUserId());
	}

	public function url(): ?Uri
	{
		return new Uri(SITE_DIR . 'crm/configs/mailtemplate/');
	}
}
