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
use Bitrix\Main\Web\Uri;

final class TaxesNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	public function __construct(
		private bool $isCatalogAvailable,
		private bool $canEditVat,
		private bool $areRequiredModulesAvailable,
		private bool $isVatMode,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'taxes';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->isCatalogAvailable && $this->canEditVat;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions) && $this->areRequiredModulesAvailable;
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		Loc::loadMessages(dirname(__DIR__, 7) . '/install/components/bitrix/crm.configs/templates/.default/template.php');
		$context->application->SetTitle(Loc::getMessage('CRM_CONFIGS_TAX'));
	}

	public function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$mode = 'list';
		foreach (['edit', 'show', 'add'] as $selector)
		{
			$value = $request->getQuery($selector);
			if (is_string($value) || is_int($value))
			{
				$mode = $selector;

				break;
			}
		}

		$id = $request->getQuery($this->isVatMode ? 'vat_id' : 'tax_id');
		$id = in_array($mode, ['edit', 'show'], true)
			&& (is_string($id) || is_int($id))
			&& preg_match('/^[0-9]+$/D', (string)$id) === 1
			? (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0)
			: 0;

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			$this->isVatMode ? 'bitrix:crm.config.vat' : 'bitrix:crm.config.tax',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'SETTINGS_HUB_URL' => $url->getUri(),
				'MODE' => $mode,
				($this->isVatMode ? 'VAT_ID' : 'TAX_ID') => $id,
			],
			'.default',
		);
	}
}
