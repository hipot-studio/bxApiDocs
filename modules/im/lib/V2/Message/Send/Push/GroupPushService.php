<?php

namespace Bitrix\Im\V2\Message\Send\Push;

use Bitrix\Im\V2\Message\Send\PushService;
use Bitrix\Im\V2\Pull\Event\MessageSend;

class GroupPushService extends PushService
{
	public function sendPush(array $counters = []): void
	{
		if (!$this->isPullEnable())
		{
			return;
		}

		$chat = $this->message->getChat();

		if ($chat->getRelations()->hasUser($this->message->getAuthorId(), $chat->getId()))
		{
			\CPushManager::DeleteFromQueueBySubTag($this->message->getAuthorId(), 'IM_MESS');
		}

		$event = new MessageSend($this->message, $this->sendingConfig, $counters);
		$event->send();

		foreach ($event->getPullByUsers() as $group)
		{
			$this->mobilePush->sendForGroupMessage(['users' => $group->getRecipients(), 'event' => $group->getParams()]);
		}
	}
}
