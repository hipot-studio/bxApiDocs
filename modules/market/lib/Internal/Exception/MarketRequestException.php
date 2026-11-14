<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class MarketRequestException extends MarketException
{
	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_NETWORK_ERROR') ?? 'Market is not responding. Please try again later';
	}
}
