<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItemLifecycle;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;

final class AnalyticsReportsNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	public function id(): string
	{
		return 'analytics-reports';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return \CCrmAuthorizationHelper::CheckConfigurationUpdatePermission(
			\CCrmPerms::GetCurrentUserPermissions(),
		);
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		return new Component(
			'bitrix:crm.widget_slot.list',
			['ENABLE_CONTROL_PANEL' => false],
			'.default',
		);
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		$context->application->SetTitle(Loc::getMessage('CRM_SETTINGS_HUB_ANALYTICS_REPORTS_TITLE'));
	}

	public function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
	}
}
