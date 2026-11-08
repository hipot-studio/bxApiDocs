<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class MailClientNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $isIntranetAvailable,
		private bool $isExternalMailAvailable,
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
		return $this->isIntranetAvailable && $this->isExternalMailAvailable;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions)
			&& $this->isMailAvailable
			&& $this->isMailToolAvailable;
	}

	public function url(): ?Uri
	{
		return $this->mailUrl;
	}
}
