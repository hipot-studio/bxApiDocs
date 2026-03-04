<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task;

use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ChatReadAllMessageService;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Counter\Service;
use Bitrix\Tasks\V2\Internal\Service\Counter\Command\AfterCommentsRead;

class ReadTaskMessagesHandler
{
	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly ChatReadAllMessageService $chatReadAllService,
		private readonly Service $counters,
	)
	{
	}

	public function __invoke(ReadTaskMessagesCommand $command): void
	{
		// Read all messages in chat.
		$chat = $this->chatRepository->getByTaskId($command->taskId);
		$this->chatReadAllService->readAllByChatId(
			$command->userId,
			$chat->getId()
		);

		// Reset counters.
		$this->counters->send(new AfterCommentsRead($command->userId, $command->taskId));
	}
}
