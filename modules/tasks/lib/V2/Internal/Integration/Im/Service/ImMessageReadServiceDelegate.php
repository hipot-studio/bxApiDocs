<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Message\ReadService;
use Bitrix\Im\V2\MessageCollection;

/**
 * @method void readAllInChat(int $chatId)
 *
 * use for only read chat messages without generation eventRead events
 * @see \Bitrix\Im\V2\Message\ReadService::readAllInChat()
 *
 * for read with eventRead events use ChatReadAllMessageService::readAllByChatId
 */
class ImMessageReadServiceDelegate extends AbstractServiceDelegate
{
	public function __construct(?int $userId = null)
	{
		parent::__construct($userId);
	}

	protected function createDelegate(...$arguments): ReadService
	{
		return new ReadService(...$arguments);
	}

	public function read(int $messageId, int $chatId): void
	{
		if (null === $this->delegate)
		{
			return;
		}

		$this->delegate->read(new MessageCollection([$messageId]), new ExternalChat($chatId));
	}
}
