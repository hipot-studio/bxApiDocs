<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class PipelinesNavigationItem implements SettingsHubUrlNavigationItem
{
	public function __construct(private Router $router)
	{
	}

	public function id(): string
	{
		return 'pipelines';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->configuration()->canManageFunnels(
			ConfigurationCallSite::CrmConfigsComponent,
			ConfigurationScope::ofCrm(),
		);
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$permissions->configuration()->canManageFunnels(
			ConfigurationCallSite::DealCategoryListAjax,
			ConfigurationScope::ofEntityType(\CCrmOwnerType::Deal),
		))
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_PIPELINES_ACCESS'),
					'CRM_SETTINGS_HUB_NAVIGATION_PIPELINES_ACCESS',
				),
			);
		}

		return new Result();
	}

	public function url(): ?Uri
	{
		return $this->router->getItemListUrl(\CCrmOwnerType::DealCategory);
	}
}
