<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Trait\UsersTrait;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\MessageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class TaskMessagesProvider
{
	use UsersTrait;

	public function __construct(
		private readonly MessageRepositoryInterface $messageRepository,
		private readonly UserRepositoryInterface $userRepository,
	)
	{
	}

	public function getRecentMessages(int $chatId, int $limit): array
	{
		$messages = $this->messageRepository->tailByChatIdWithoutSystemMessages(
			$chatId,
			$limit,
		);

		if (empty($messages))
		{
			return [];
		}

		return $this->appendUserNames($messages);
	}
}
