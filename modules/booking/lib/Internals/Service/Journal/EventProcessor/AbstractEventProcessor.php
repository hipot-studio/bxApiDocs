<?php

namespace Bitrix\Booking\Internals\Service\Journal\EventProcessor;

use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalEventCollection;
use Bitrix\Main\Event;

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

	protected function sendBitrixEvent(string $type, array $parameters): void
	{
		(new Event(
			moduleId: 'booking',
			type: $type,
			parameters: $parameters,
		))->send();
	}
}
