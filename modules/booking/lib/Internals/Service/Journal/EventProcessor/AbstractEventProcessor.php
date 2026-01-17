<?php

namespace Bitrix\Booking\Internals\Service\Journal\EventProcessor;

use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalEventCollection;

abstract class AbstractEventProcessor implements EventProcessor
{
	abstract public function processOne(JournalEvent $event): void;

	public function process(JournalEventCollection $eventCollection): void
	{
		foreach ($eventCollection as $event)
		{
			$this->processOne($event);
		}
	}
}
