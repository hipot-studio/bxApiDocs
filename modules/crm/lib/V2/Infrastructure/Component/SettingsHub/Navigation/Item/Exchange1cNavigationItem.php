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

final class Exchange1cNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	private const SUPPORTED_LANGUAGES = ['ru', 'ua'];
	private const QUERY_FLAGS = ['catalog', 'invoice'];

	public function __construct(
		private string $siteLanguage,
		private bool $isIblockAvailable,
		private bool $isCurrencyAvailable,
		private bool $isSaleAvailable,
		private bool $isCatalogAvailable,
	)
	{
	}

	public function id(): string
	{
		return 'exchange-1c';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return true;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return in_array($this->siteLanguage, self::SUPPORTED_LANGUAGES, true)
			&& $permissions->isCrmAdmin()
			&& $this->isIblockAvailable
			&& $this->isCurrencyAvailable
			&& $this->isSaleAvailable
			&& $this->isCatalogAvailable;
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$parameters = [
			'SEF_MODE' => 'N',
			'HIDE_CONTROL_PANEL' => 'Y',
			'HIDE_TOOLBAR' => 'Y',
		];

		foreach (self::QUERY_FLAGS as $flag)
		{
			if ($request->getQuery($flag) !== null)
			{
				$parameters[$flag] = 'Y';
			}
		}

		return new Component(
			'bitrix:crm.config.exch1c',
			$parameters,
			'.default',
		);
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		$context->application->SetTitle(Loc::getMessage('CRM_SETTINGS_HUB_EXCHANGE_1C_TITLE'));

		// Workaround for legacy markup rendered without crm.control_panel in the shared slider.
		$context->asset->addCss('/bitrix/js/crm/css/crm.css');
	}

	public function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
	}
}
