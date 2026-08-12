<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectChatDataProvider;

class ProjectChatResolver
{
	public function __construct(
		private readonly ProjectChatDataProvider $projectChatDataProvider,
	)
	{
	}

	public function getByProjectId(int $projectId): ?int
	{
		return $this->getByProjectIds([$projectId])[$projectId] ?? null;
	}

	/**
	 * @param int[] $projectIds
	 * @return array<int, ?int>
	 */
	public function getByProjectIds(array $projectIds): array
	{
		$result = [];
		foreach ($this->projectChatDataProvider->getByProjectIds($projectIds) as $projectId => $chatData)
		{
			$result[$projectId] = $chatData['chatId'] ?? null;
		}

		return $result;
	}

	public function getProjectIdByChatId(int $chatId): ?int
	{
		return $this->projectChatDataProvider->getProjectIdByChatId($chatId);
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, int> map chatId => projectId (only resolved project chats)
	 */
	public function getProjectIdsByChatIds(array $chatIds): array
	{
		return $this->projectChatDataProvider->getProjectIdsByChatIds($chatIds);
	}
}
