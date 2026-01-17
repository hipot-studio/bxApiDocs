<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Im\V2\Chat;

class MediaMessageEvent extends ChatEvent
{
	private string $category;

	public function __construct(string $eventName, Chat $chat, int $userId, string $category)
	{
		$this->category = $category;
		parent::__construct($eventName, $chat, $userId);
	}

	protected function getCategory(string $eventName): string
	{
		return $this->category;
	}
}
