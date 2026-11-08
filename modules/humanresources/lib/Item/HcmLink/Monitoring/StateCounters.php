<?php

namespace Bitrix\HumanResources\Item\HcmLink\Monitoring;

use Bitrix\HumanResources\Contract\Item;

class StateCounters implements Item
{
	/**
	 * The six states are disjoint and add up to total(). The seventh, canceledByAgent, is a subset
	 * of canceled, stays out of the sum and is read by the verdict alone.
	 */
	public function __construct(
		public readonly int $waiting = 0,
		public readonly int $notPickedUp = 0,
		public readonly int $inProgress = 0,
		public readonly int $unfinished = 0,
		public readonly int $canceled = 0,
		public readonly int $done = 0,
		public readonly int $canceledByAgent = 0,
	)
	{
	}

	public function total(): int
	{
		return $this->waiting
			+ $this->notPickedUp
			+ $this->inProgress
			+ $this->unfinished
			+ $this->canceled
			+ $this->done
		;
	}
}
