<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Factory;

use Bitrix\Calendar\Internals\Counter;

class CounterFactory
{
	public function factory(int $userId): Counter
	{
		return Counter::getInstance($userId);
	}
}
