<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Factory\Chat;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Builder\MessageBuilder;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\MessageDataInterface;

class ChatMessageSender
{
	public function __construct(
		private readonly MessageBuilder $builder,
		private readonly Chat $chatFactory,
	)
	{
	}

	public function sendMessage(int $chatId, MessageDataInterface $messageData): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if ($chatId <= 0)
		{
			return;
		}

		$chat = $this->chatFactory->getExistedChat($chatId);

		if (!$chat)
		{
			return;
		}

		$message = $this->builder->build($messageData);
		$contextUserId = $message->getContext()->getUserId();

		if ($contextUserId > 0)
		{
			$chat = $chat->withContextUser($contextUserId);
		}

		$chat->sendMessage($message);
	}
}
