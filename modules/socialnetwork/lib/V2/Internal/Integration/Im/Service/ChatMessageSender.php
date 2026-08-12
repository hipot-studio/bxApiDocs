<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Message\Send\SendingConfig;
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

	/**
	 * Sends a system message into the chat and returns the created message id (null when not sent).
	 *
	 * $silent = true suppresses the unread counter, the push and the in-app toast/sound
	 * while keeping the message in the chat and raising it in the recent list. The im V2
	 * sending specifics (SendingConfig, message notify flag) are confined to this integration
	 * layer; callers express intent with the domain-level $silent flag.
	 */
	public function sendMessage(int $chatId, MessageDataInterface $messageData, bool $silent = false): ?int
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		if ($chatId <= 0)
		{
			return null;
		}

		$chat = $this->chatFactory->getExistedChat($chatId);

		if (!$chat)
		{
			return null;
		}

		$message = $this->builder->build($messageData);
		$contextUserId = $message->getContext()->getUserId();

		if ($contextUserId > 0)
		{
			$chat = $chat->withContextUser($contextUserId);
		}

		if (!$silent)
		{
			return $chat->sendMessage($message)->getMessageId();
		}

		$message->disableNotify();

		return $chat->sendMessage($message, $this->createSilentConfig())->getMessageId();
	}

	/**
	 * Builds the sending config that suppresses the counter and the push but keeps the chat
	 * in the recent list (addRecent stays default-true).
	 */
	private function createSilentConfig(): SendingConfig
	{
		return (new SendingConfig())
			->enableSkipCounterIncrements()
			->disallowSendPush()
		;
	}
}
