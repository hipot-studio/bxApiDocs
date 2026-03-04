<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\ReadAllMessages;

use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ChatReadAllMessageService;
use Bitrix\Tasks\V2\Internal\Logger;

class ReadAllMessagesService
{
	public function __construct(
		private readonly Logger $logger,
		private readonly ChatReadAllMessageService $chatReadAllMessageService,
	)
	{
	}

	/** @param int[] $chatIds */
	public function execute(int $userId, array $chatIds): void
	{
		foreach ($chatIds as $chatId)
		{
			try
			{
				$this->chatReadAllMessageService
					->readAllByChatId($userId, $chatId);
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
			}
		}
	}
}
