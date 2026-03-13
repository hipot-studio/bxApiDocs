<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class MarketException extends \Bitrix\Main\SystemException
{
	public function __construct($message = "", $code = 0, $file = "", $line = 0, \Throwable $previous = null)
	{
		$message = empty($message) ? $this->getLocalMessage() : $message;

		parent::__construct($message, $code, $file, $line, $previous);
	}

	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_MARKET') ?? 'Market exception';
	}
}
