<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Async;

use Bitrix\Main;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Tasks\V2\Internal\Async\QueueId;
use Bitrix\Tasks\V2\Internal\EventDispatcher;

class Message extends AbstractMessage
{
	public function __construct
	(
		public array $events = [],
	)
	{
	}

	public function jsonSerialize(): mixed
	{
		return ['serialized' => serialize($this->events)];
	}

	public static function createFromData(array $data): MessageInterface
	{
		if (!array_key_exists('serialized', $data))
		{
			return new static();
		}

		$events = unserialize($data['serialized'], ['allowed_classes' => [Main\Event::class, EventDispatcher\Event::class]]);

		if (!$events)
		{
			return new static();
		}

		return new static($events);
	}

	protected function getQueueId(): QueueId
	{
		return QueueId::EventDispatcher;
	}
}
