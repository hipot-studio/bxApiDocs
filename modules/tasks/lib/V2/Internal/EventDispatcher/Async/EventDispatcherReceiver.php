<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Async;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher\EventDispatcher;

class EventDispatcherReceiver extends AbstractReceiver
{
	public function __construct(
		private readonly EventDispatcher $eventDispatcher,
	)
	{
	}

	public function process(MessageInterface $message): void
	{
		if (!$message instanceof Message)
		{
			return;
		}

		if (empty($message->events))
		{
			return;
		}

		foreach ($message->events as $event)
		{
			$this->eventDispatcher->dispatch($event);
		}
	}
}
