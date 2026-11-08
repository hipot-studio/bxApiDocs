<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance\Vibecode;

use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\AbstractMicroserviceClient;

final class BalanceClient extends AbstractMicroserviceClient
{
	private const ACTION = 'balance.get';

	public function fetch(): Result
	{
		return $this->performRequest(self::ACTION, []);
	}
}
