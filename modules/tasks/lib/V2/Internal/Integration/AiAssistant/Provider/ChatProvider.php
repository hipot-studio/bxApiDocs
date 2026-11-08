<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetChatMessagesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\Mapper\ChatMessageResponseMapper;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;

class ChatProvider
{
	public function __construct(
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly TaskMessagesProvider $taskMessagesProvider,
		private readonly ChatMessageResponseMapper $messageResponseMapper,
	)
	{
	}

	/**
	 * @throws InvalidIdentifierException
	 */
	public function getMessages(GetChatMessagesDto $dto, int $userId): array
	{
		if ($dto->taskId === null || $dto->taskId <= 0)
		{
			throw new InvalidIdentifierException();
		}

		$chat = $this->chatRepository->getByTaskId($dto->taskId);

		$messages =
			($chat !== null)
				? $this->taskMessagesProvider->getRecentMessages($chat->id, $dto->limit + 1, $dto->lastId)
				: []
		;

		$hasMore = count($messages) > $dto->limit;
		if ($hasMore)
		{
			$messages = array_slice($messages, 0, $dto->limit);
		}

		$lastMessage = $hasMore ? end($messages) : null;
		$nextLastId = $lastMessage['ID'] ?? null;

		return [
			'taskId' => $dto->taskId,
			'messages' => $this->messageResponseMapper->mapFromArrays($messages, $userId),
			'limit' => $dto->limit,
			'nextLastId' => $nextLastId,
			'hasMore' => $hasMore,
		];
	}
}
