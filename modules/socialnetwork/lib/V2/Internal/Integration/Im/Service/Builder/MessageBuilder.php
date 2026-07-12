<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Builder;

use Bitrix\Im;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Factory\Message;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\MessageDataInterface;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message\WithAttachInterface;

class MessageBuilder
{
	public function __construct(
		private readonly Message $messageFactory,
	)
	{
	}

	public function build(MessageDataInterface $messageData): Im\V2\Message
	{
		$message = $this->makeMessage($messageData);

		if ($messageData instanceof WithAttachInterface)
		{
			$this->addAttach($message, $messageData);
		}

		return $message;
	}

	private function makeMessage(MessageDataInterface $messageData): Im\V2\Message
	{
		$message = $this->messageFactory->createMessage();
		$message->setMessage($messageData->getText());
		$message->setAuthorId($messageData->getAuthorId());
		$message->setContextUser($messageData->getContextUserId());

		return $message;
	}

	private function addAttach(Im\V2\Message $message, WithAttachInterface $messageData): void
	{
		$attach = $messageData->getAttach();

		if ($attach)
		{
			$message->setAttach($attach);
		}
	}
}
