<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\Item;

use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation\SettingsHubWrappedNavigationItem;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

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

	public function checkAvailability(UserPermissions $permissions): Result
	{
		if ($this->isCrmPgsqlDb)
		{
			return (new Result())->addError(
				new Error(
					Loc::getMessage('CRM_SETTINGS_HUB_CRMVOLUME_DATABASE_UNAVAILABLE'),
					'CRMVOLUME_DATABASE_UNAVAILABLE',
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
