<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class RestNotRegisteredException extends MarketException
{
	public function __construct(\Throwable $previous = null)
	{
		parent::__construct($this->getLocalMessage(), 0, __FILE__, __LINE__, $previous);
	}

	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_REST_NOT_REGISTERED')
				?? 'Your Bitrix24 is not registered on the oauth server'
		;
	}
}
