<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Event\Payload;

use Bitrix\Bizproc\Starter\Event;
use Bitrix\Main\Type\Contract\Arrayable;

class ListenerParameters implements Arrayable
{
	public const KEY_EVENT = 'EVENT';
	public function __construct(public Event $event)
	{}

	public function toArray()
	{
		return [
			self::KEY_EVENT => $this->event,
		];
	}
}