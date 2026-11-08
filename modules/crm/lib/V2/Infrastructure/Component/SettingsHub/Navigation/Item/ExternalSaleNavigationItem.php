<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

final class ExternalSaleNavigationItem implements SettingsHubWrappedNavigationItem
{
	public function __construct(
		private string $languageId,
		private bool $areRequiredModulesAvailable,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'external-sale';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && in_array($this->languageId, ['ru', 'ua'], true);
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions) && $this->areRequiredModulesAvailable;
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$page = $request->getQuery('page');
		if (!in_array($page, ['index', 'edit', 'sync'], true))
		{
			$page = 'index';
		}

		$id = 0;
		if ($page !== 'index')
		{
			$requestedId = $request->getQuery('id') ?? '0';
			$id = (is_string($requestedId) || is_int($requestedId))
				&& preg_match('/^[0-9]+$/D', (string)$requestedId) === 1
				? filter_var($requestedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
				: false;
			if ($id === false)
			{
				$page = 'index';
				$id = 0;
			}
		}

		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.config.external_sale',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'IS_SETTINGS_HUB' => true,
				'SETTINGS_HUB_URL' => $url->getUri(),
				'PAGE' => $page,
				'ID' => $id,
			],
			'.default',
		);
	}
}
