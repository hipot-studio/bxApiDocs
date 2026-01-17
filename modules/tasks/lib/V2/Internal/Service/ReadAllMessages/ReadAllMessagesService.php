<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\ReadAllMessages;

use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ImMessageReadServiceDelegate;
use Bitrix\Tasks\V2\Internal\Logger;

class ReadAllMessagesService
{
	public function __construct(
		private readonly Logger $logger,
	) {
	}

	/** @param int[] $chatIds */
	public function execute(int $userId, array $chatIds): void
	{
		$service = new ImMessageReadServiceDelegate($userId);

		foreach ($chatIds as $chatId)
		{
			try
			{
				$service->readAllInChat($chatId);
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
			}
		}
	}
}
