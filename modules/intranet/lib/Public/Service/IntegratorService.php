<?php

namespace Bitrix\Intranet\Public\Service;

use Bitrix\Bitrix24\License;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class IntegratorService
{
	public static function createByDefault(): IntegratorService
	{
		return new IntegratorService();
	}

	public function isRenamedIntegrator(): bool
	{
		return Loader::includeModule('bitrix24')
			? License::getCurrent()->isCIS()
			: Application::getInstance()->getLicense()->isCis();
	}
}
