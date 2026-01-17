<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Provider;

use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Public;

class ChatProvider
{
	private ?Public\Provider\ChatProvider $chatProvider = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->chatProvider = Container::getInstance()->get(Public\Provider\ChatProvider::class);
		}
	}

	public function getChatByTaskId(int $taskId): Chat
	{
		$chatEntity = $this->chatProvider?->getByTaskId($taskId);

		$chatId = $chatEntity?->getId();

		return Chat::getInstance($chatId);
	}
}
