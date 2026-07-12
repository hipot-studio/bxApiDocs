<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\Counter;

use Bitrix\Tasks\Internals\Counter;

class CounterFactory
{
	public function getCounter(int $userId): Counter
	{
		return Counter::getInstance($userId);
	}
}
