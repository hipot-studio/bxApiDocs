<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Im;

class InMemoryChatRepository implements ChatRepositoryInterface
{
	private ChatRepositoryInterface $chatRepository;
	/** @var Im\Entity\Chat[] */
	private array $cache = [];
	/** @var array<string, int[]> */
	private array $chatIdsByTaskIdsCache = [];
	/** @var array<int, int[]> */
	private array $chatIdsByUserIdCache = [];

	public function __construct(ChatRepository $chatRepository)
	{
		$this->chatRepository = $chatRepository;
	}

	public function getByTaskId(int $taskId): ?Im\Entity\Chat
	{
		if (isset($this->cache[$taskId]))
		{
			return $this->cache[$taskId];
		}

		$chat = $this->chatRepository->getByTaskId($taskId);

		if ($chat !== null)
		{
			$this->cache[$taskId] = $chat;
		}

		return $chat;
	}

	public function findChatIdsByTaskIds(array $taskIds): array
	{
		$indices = array_unique(array_map('intval', $taskIds));
		sort($indices);

		$key = implode(',', $indices);

		if (!array_key_exists($key, $this->chatIdsByTaskIdsCache))
		{
			$this->chatIdsByTaskIdsCache[$key] = $this->chatRepository->findChatIdsByTaskIds($taskIds);
		}

		return $this->chatIdsByTaskIdsCache[$key];
	}

	public function findChatIdsByUserIdAndGroupIds(int $userId, array $groupIds): array
	{
		if (!array_key_exists($userId, $this->chatIdsByUserIdCache))
		{
			$this->chatIdsByUserIdCache[$userId] = $this->chatRepository->findChatIdsByUserIdAndGroupIds($userId, $groupIds);
		}

		return $this->chatIdsByUserIdCache[$userId];
	}

	public function save(int $chatId, int $taskId): void
	{
		if (isset($this->cache[$taskId]))
		{
			unset($this->cache[$taskId]);
		}

		$this->chatRepository->save($chatId, $taskId);

		$this->cache[$taskId] = new Im\Entity\Chat(
			id: $chatId,
			entityId: $taskId,
			entityType: Im\Chat::ENTITY_TYPE,
		);
	}

	public function delete(int $taskId): void
	{
		$this->chatRepository->delete($taskId);

		if (isset($this->cache[$taskId]))
		{
			unset($this->cache[$taskId]);
		}
	}
}
