<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class ApplicationNotFoundException extends MarketException
{
	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_APPLICATION_NOT_FOUND') ?? 'The application is not found';
	}
}
