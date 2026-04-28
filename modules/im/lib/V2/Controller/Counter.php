<?php

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;

class Counter extends BaseController
{
	/**
	 * @restMethod im.v2.Counter.get
	 */
	public function getAction(UserCountersCollector $collector): ?array
	{
		$counters = $collector->get((int)$this->getCurrentUser()->getId());

		return $this->toRestFormat($counters);
	}
}