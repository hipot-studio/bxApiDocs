<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\ChatHolder;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;

class ExternalChatLoadedNotifier extends Base
{
	public function onBeforeAction(Event $event)
	{
		$chat = $this->extractChat($this->getAction()->getArguments());

		if (!($chat instanceof ExternalChat) || $chat->getChatId() <= 0)
		{
			return null;
		}

		$userId = $chat->getContext()->getUserId();

		Application::getInstance()->addBackgroundJob(
			static fn () => $chat->onAfterLoad($userId),
			[],
			Application::JOB_PRIORITY_LOW,
		);

		return null;
	}

	private function extractChat(array $arguments): ?Chat
	{
		foreach ($arguments as $arg)
		{
			if ($arg instanceof Chat)
			{
				return $arg;
			}
			if ($arg instanceof ChatHolder)
			{
				return $arg->getChat();
			}
		}

		return null;
	}
}
