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
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

final class ProductPropertiesNavigationItem implements SettingsHubWrappedNavigationItemLifecycle
{
	public function __construct(
		private bool $isCatalogAvailable,
		private bool $canReadCatalog,
		private bool $areRequiredModulesAvailable,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'product-properties';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && $this->isCatalogAvailable && $this->canReadCatalog;
	}

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if (!$this->areRequiredModulesAvailable)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_PRODUCT_PROPERTIES_MODULES'),
					'CRM_SETTINGS_HUB_NAVIGATION_PRODUCT_PROPERTIES_MODULES',
				),
			);
		}

		return new Result();
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function beforeRender(SettingsHubRenderContext $context): void
	{
		Loc::loadMessages(dirname(__DIR__, 7) . '/install/components/bitrix/crm.configs/templates/.default/template.php');
		$context->application->SetTitle(Loc::getMessage('CRM_CONFIGS_PRODUCT_PROPS'));
	}

	public function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$mode = 'list';
		$edit = $request->getQuery('edit');
		$add = $request->getQuery('add');
		if (is_string($edit) || is_int($edit))
		{
			$mode = 'edit';
		}
		elseif (is_string($add) || is_int($add))
		{
			$mode = 'add';
		}

		$propId = $request->getQuery('prop_id');
		$propId = $mode === 'edit'
			&& (is_string($propId) || is_int($propId))
			&& preg_match('/^[0-9]+$/D', (string)$propId) === 1
			? (filter_var($propId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0)
			: 0;

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.product.props',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'SETTINGS_HUB_URL' => $url->getUri(),
				'MODE' => $mode,
				'PROP_ID' => $propId,
			],
			'.default',
		);
	}
}
