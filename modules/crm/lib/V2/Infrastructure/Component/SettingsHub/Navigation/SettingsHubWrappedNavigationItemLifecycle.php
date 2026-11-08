<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;

use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;

interface SettingsHubWrappedNavigationItemLifecycle extends SettingsHubWrappedNavigationItem
{
	/** Temporary workaround for legacy pages that require setup before the shared slider is rendered. */
	public function beforeRender(SettingsHubRenderContext $context): void;

	/** Temporary workaround for legacy pages that need to configure the shared side panel. */
	public function configureSidePanel(SidePanelWrapper $sidePanel): void;
}
