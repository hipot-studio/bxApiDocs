<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class DirectoriesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private bool $areRequiredModulesAvailable)
	{
	}

	public function id(): string
	{
		return 'directories';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->configuration()->canManageStages(
			ConfigurationCallSite::CrmConfigsComponent,
			ConfigurationScope::ofCrm(),
		);
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->areRequiredModulesAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_DIRECTORIES_MODULES_UNAVAILABLE'),
					'DIRECTORIES_MODULES_UNAVAILABLE',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		$localPath = getLocalPath('components/bitrix/crm.config.status/slider.php');
		if (!$localPath)
		{
			return null;
		}

		return new Uri($localPath);
	}
}
