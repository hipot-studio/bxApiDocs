<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class RestModuleNotIncludedException extends MarketException
{
	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_REST_MODULE_NOT_INCLUDED') ?? 'The Rest module is not included';
	}
}
