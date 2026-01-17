<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\ReadAllMessages;

use Bitrix\Main\Config\Option;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Throwable;

class ReadAllMessagesQuery
{
	private const DEFAULT_CHUNK_SIZE = 50;

	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly Logger $logger,
	) {
	}

	public function execute(int $userId, ?int $groupId = null): void
	{
		$taskIdsWithChatIds = $this->taskRepository->findTasksIdsWithChatIdsAndActiveCountersByUserIdAndGroupId($userId, $groupId);
		$chatIds = array_map('intval', array_column($taskIdsWithChatIds, 'TASK_CHAT_ID'));

		foreach (array_chunk($chatIds, $this->getChunkSize()) as $chatIdsChunk)
		{
			try
			{
				$message = new ReadAllMessagesMessage($userId, $chatIdsChunk);
				$message->sendByInternalQueueId();
			}
			catch (Throwable $e)
			{
				$this->logger->logError($e);
			}
		}
	}

	private function getChunkSize(): int
	{
		return (int)Option::get('tasks', 'read_all_messages_chunk_size', self::DEFAULT_CHUNK_SIZE);
	}
}
