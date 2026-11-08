<?php

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Main\HttpRequest;

interface SettingsHubWrappedNavigationItem extends SettingsHubNavigationItem
{
	/** @return array<string, mixed> */
	public function urlQueryParameters(): array;

	public function component(HttpRequest $request): Contract\Component;
}
