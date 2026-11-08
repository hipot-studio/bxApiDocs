<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubUrlNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Main\Web\Uri;

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
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $permissions->configuration()->canManageFunnels(
			ConfigurationCallSite::DealCategoryListAjax,
			ConfigurationScope::ofEntityType(\CCrmOwnerType::Deal),
		);
	}

	public function url(): ?Uri
	{
		return $this->router->getItemListUrl(\CCrmOwnerType::DealCategory);
	}
}
