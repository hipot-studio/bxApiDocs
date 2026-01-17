<?php

namespace Bitrix\Rest\Integration\Market;

use Bitrix\Main\Application;

class Label
{
	public static function isRenamedMarket(): bool
	{
		return Application::getInstance()->getLicense()->getRegion() === 'ru';
	}
}