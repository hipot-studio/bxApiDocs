<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadChatsByTypeBatchTasksTask;

use Bitrix\Im\V2\Message\Event\AfterReadChatsByTypeBatchEvent;
use Bitrix\Main\Config\Option;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter;

class UpdateCounters
{
	private const DEFAULT_CHUNK_SIZE = 200;
	private const OPTION_CHUNK_SIZE = 'read_chats_by_type_chunk_size';

	public function __construct(
		private readonly ChatRepositoryInterface $repository,
		private readonly Counter\Service $counters,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadChatsByTypeBatchEvent $event): void
	{
		$userId = $event->getUserId();
		$chatIds = $event->getChatIds();

		if (empty($chatIds))
		{
			return;
		}

		$chunkSize = $this->getChunkSize();

		foreach (array_chunk($chatIds, $chunkSize) as $chunk)
		{
			try
			{
				$taskIds = $this->repository->findTaskIdsByChatIds($chunk);
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
				continue;
			}

			if (empty($taskIds))
			{
				continue;
			}

			try
			{
				$this->counters->send(new Counter\Command\AfterCommentsReadList($userId, $taskIds));
			}
			catch (\Throwable $e)
			{
				$this->logger->logError($e);
			}
		}
	}

	private function getChunkSize(): int
	{
		$chunkSize = (int)Option::get('tasks', self::OPTION_CHUNK_SIZE, self::DEFAULT_CHUNK_SIZE);

		return $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;
	}
}
