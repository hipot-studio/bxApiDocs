<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Journal\EventProcessor;

use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalEventCollection;

interface EventProcessor
{
	public function process(JournalEventCollection $eventCollection): void;

	public function processOne(JournalEvent $event): void;
}
