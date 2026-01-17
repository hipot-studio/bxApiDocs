<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Repository\Mapper;

use Bitrix\Im\V2\Message;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Chat;

class MessageMapper
{
	public function mapToEntity(
		Message $message,
	): Entity\Message
	{
		$messageChat = $message->getChat();

		$chat = Chat::mapFromArray([
			'id' => $messageChat->getId(),
			'entityId' => $messageChat->getEntityId() ,
			'entityType' => $messageChat->getEntityType(),
		]);

		return new Entity\Message(
			id: $message->getId(),
			chatId: $message->getChatId(),
			text: $message->getMessage(),
			fileIds: $message->getFileIds(),
			previewId: $message->getUrl()?->getRichData()?->getId(),
			chat: $chat,
		);
	}
}
