<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItemLifecycle;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

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
		return $permissions->isCrmAdmin()
			&& in_array($this->siteLanguage, self::SUPPORTED_LANGUAGES, true);
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->isIblockAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_EXCHANGE1C_IBLOCK_UNAVAILABLE'),
					'EXCHANGE1C_IBLOCK_UNAVAILABLE',
				),
			);
		}

		if (!$this->isCurrencyAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_EXCHANGE1C_CURRENCY_UNAVAILABLE'),
					'EXCHANGE1C_CURRENCY_UNAVAILABLE',
				),
			);
		}

		if (!$this->isSaleAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_EXCHANGE1C_SALE_UNAVAILABLE'),
					'EXCHANGE1C_SALE_UNAVAILABLE',
				),
			);
		}

		if (!$this->isCatalogAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_EXCHANGE1C_CATALOG_UNAVAILABLE'),
					'EXCHANGE1C_CATALOG_UNAVAILABLE',
				),
			);
		}

		return new Result();
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
