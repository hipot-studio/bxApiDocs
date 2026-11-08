<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

final class CrmVolumeNavigationItem implements SettingsHubWrappedNavigationItem
{
	public function __construct(
		private bool $isPgsqlConnection,
		private bool $isCrmPgsqlDb,
		private Uri $staticPageUrl,
	)
	{
	}

	public function id(): string
	{
		return 'crm-volume';
	}

	public function isVisible(UserPermissions $permissions): bool
	{
		return $permissions->isCrmAdmin() && !$this->isPgsqlConnection;
	}

	public function canRead(UserPermissions $permissions): bool
	{
		return $this->isVisible($permissions) && !$this->isCrmPgsqlDb;
	}

	public function urlQueryParameters(): array
	{
		return [];
	}

	public function component(HttpRequest $request): Contract\Component
	{
		$url = clone $this->staticPageUrl;
		$url->addParams([
			'IFRAME' => 'Y',
			'IFRAME_TYPE' => 'SIDE_SLIDER',
		]);

		return new Component(
			'bitrix:crm.volume',
			[
				'SEF_MODE' => 'N',
				'ENABLE_CONTROL_PANEL' => false,
				'SETTINGS_HUB_URL' => $url->getUri(),
			],
			'.default',
		);
	}
}
