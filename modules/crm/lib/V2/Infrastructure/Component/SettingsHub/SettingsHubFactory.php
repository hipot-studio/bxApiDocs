<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering\SettingsHubRenderContext;
use Bitrix\Main\Page\Asset;

class SettingsHubFactory
{
	public function getRouter(): Router
	{
		return Container::getInstance()->getRouter();
	}

	public function createRenderContext(): SettingsHubRenderContext
	{
		return new SettingsHubRenderContext(
			$GLOBALS['APPLICATION'],
			Asset::getInstance(),
		);
	}
}
