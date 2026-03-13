<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Exception;

use Bitrix\Main\Localization\Loc;

class MarketRequestException extends MarketException
{
	public function __construct(?array $httpClientErrors = null)
	{
		$message = $this->getLocalMessage() . $this->prepareMessages($httpClientErrors);
		parent::__construct($message);
	}

	protected function getLocalMessage(): string
	{
		return Loc::getMessage('MARKET_INTERNAL_EXCEPTION_REQUEST_EXCEPTION') ?? 'Market request error';
	}

	protected function prepareMessages(?array $httpClientErrors = null): string
	{
		$httpClientErrors = (is_array($httpClientErrors) ? $httpClientErrors : []);
		$result = [];
		foreach ($httpClientErrors as $httpClientErrorCode  => $httpClientErrorMessage )
		{
			$result[] = sprintf(
				"%s [%s]",
				$httpClientErrorMessage,
				$httpClientErrorCode,
			);
		}

		$result = implode(", ", $result);
		return empty($result) ? '' : ' (' . $result . ')';
	}
}
