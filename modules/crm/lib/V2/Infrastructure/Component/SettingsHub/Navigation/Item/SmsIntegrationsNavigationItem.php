<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Web\Uri;

final class SmsIntegrationsNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(
		private bool $hasConfigurableSenders,
		private bool $isMessageServiceAvailable,
		private bool $isMessageServiceAdmin,
		private Uri $manageUrl,
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

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions)
			&& $this->isMessageServiceAvailable
			&& $this->isMessageServiceAdmin;
	}

	public function url(): ?Uri
	{
		return (clone $this->manageUrl)->addParams(['page' => 'providers']);
	}
}
