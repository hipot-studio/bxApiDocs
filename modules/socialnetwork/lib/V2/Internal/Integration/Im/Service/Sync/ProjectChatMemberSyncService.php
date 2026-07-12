<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Sync;

use Bitrix\Im\V2\Relation;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Result\ProjectChatMemberSyncResult;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Factory;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Repository\ChatMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Im;

class ProjectChatMemberSyncService
{
	private const DEFAULT_CHUNK_SIZE = 20;

	private const CONTEXT_USER = 0;

	public function __construct(
		private readonly ProjectMemberRepositoryInterface $projectMemberRepository,
		private readonly ChatMemberRepositoryInterface $chatMemberRepository,
		private readonly Factory\Chat $chatFactory,
	)
	{

	}

	public function syncChunk(
		int $groupId,
		int $chatId,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		int $lastAddUserId = 0,
		int $lastDeleteUserId = 0,
	): ProjectChatMemberSyncResult
	{
		$result = (new ProjectChatMemberSyncResult())
			->setLastAddUserId($lastAddUserId)
			->setLastDeleteUserId($lastDeleteUserId)
		;

		$chunkSize = $chunkSize > 0 ? $chunkSize : self::DEFAULT_CHUNK_SIZE;

		if (!$this->isAvailable())
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		$chat = $this->chatFactory->getExistedChat($chatId);
		if (!$chat)
		{
			return $result->addError(new Error('Chat was not found'));
		}

		[$projectUserIds, $chatUserIds] = $this->reloadUserIds($groupId, $chatId);

		[$toAdd, $toDelete] = $this->getChunkedUserIds(
			$projectUserIds,
			$chatUserIds,
			$chunkSize,
			$lastAddUserId,
			$lastDeleteUserId,
		);

		if (!empty($toAdd))
		{
			$addResult = $this->addUsersToChat($chat, $toAdd);
			$result->addErrors($addResult->getErrors());
		}
		if (!empty($toDelete))
		{
			$deleteResult = $this->deleteUsersFromChat($chat, $toDelete);
			$result->addErrors($deleteResult->getErrors());
		}

		$lastAddUserId = !empty($toAdd) ? max($toAdd) : $lastAddUserId;
		$lastDeleteUserId = !empty($toDelete) ? max($toDelete) : $lastDeleteUserId;

		[$projectUserIds, $chatUserIds] = $this->reloadUserIds($groupId, $chatId);

		$result
			->setLastAddUserId($lastAddUserId)
			->setLastDeleteUserId($lastDeleteUserId)
			->addErrors($this->getSyncValidationResult($projectUserIds, $chatUserIds, $toAdd, $toDelete)->getErrors())
			->setHasMore($this->hasMore($projectUserIds, $chatUserIds, $lastAddUserId, $lastDeleteUserId))
		;

		return $result;
	}

	private function reloadUserIds(int $groupId, int $chatId): array
	{
		return [
			$this->projectMemberRepository->getMemberUserIds($groupId),
			$this->chatMemberRepository->getMemberUserIds($chatId),
		];
	}

	private function getChunkedUserIds(
		array $projectUserIds,
		array $chatUserIds,
		int $chunkSize,
		int $lastAddUserId,
		int $lastDeleteUserId,
	): array
	{
		return [
			array_slice($this->getUserIdsToAdd($projectUserIds, $chatUserIds, $lastAddUserId), 0, $chunkSize),
			array_slice($this->getUserIdsToDelete($projectUserIds, $chatUserIds, $lastDeleteUserId), 0, $chunkSize),
		];
	}

	private function getUserIdsToAdd(array $projectUserIds, array $chatUserIds, int $lastAddUserId): array
	{
		return $this->filterAndSortUserIds(
			array_diff($projectUserIds, $chatUserIds),
			$lastAddUserId,
		);
	}

	private function getUserIdsToDelete(array $projectUserIds, array $chatUserIds, int $lastDeleteUserId): array
	{
		return $this->filterAndSortUserIds(
			array_diff($chatUserIds, $projectUserIds),
			$lastDeleteUserId,
		);
	}

	private function filterAndSortUserIds(array $userIds, int $lastUserId): array
	{
		$userIds = array_values(array_map('intval', $userIds));
		sort($userIds, SORT_NUMERIC);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId > $lastUserId,
		));
	}

	private function hasMore(
		array $projectUserIds,
		array $chatUserIds,
		int $lastAddUserId,
		int $lastDeleteUserId,
	): bool
	{
		return
			!empty($this->getUserIdsToAdd($projectUserIds, $chatUserIds, $lastAddUserId))
			|| !empty($this->getUserIdsToDelete($projectUserIds, $chatUserIds, $lastDeleteUserId))
		;
	}

	private function getSyncValidationResult(
		array $projectUserIds,
		array $chatUserIds,
		array $toAdd,
		array $toDelete,
	): ProjectChatMemberSyncResult
	{
		$result = new ProjectChatMemberSyncResult();

		$usersNotAddedToChat = array_values(array_intersect(
			$toAdd,
			$this->getUserIdsToAdd($projectUserIds, $chatUserIds, 0),
		));
		if (!empty($usersNotAddedToChat))
		{
			$result->addError(new Error(sprintf(
				'Unable to add users to chat: %s',
				implode(', ', $usersNotAddedToChat),
			)));
		}

		$usersNotDeletedFromChat = array_values(array_intersect(
			$toDelete,
			$this->getUserIdsToDelete($projectUserIds, $chatUserIds, 0),
		));
		if (!empty($usersNotDeletedFromChat))
		{
			$result->addError(new Error(sprintf(
				'Unable to delete users from chat: %s',
				implode(', ', $usersNotDeletedFromChat),
			)));
		}

		return $result;
	}

	private function addUsersToChat(Im\V2\Chat $chat, array $toAdd): ProjectChatMemberSyncResult
	{
		$result = new ProjectChatMemberSyncResult();

		$config = new Relation\AddUsersConfig(
			hideHistory: false,
			withMessage: false,
			skipRecent: true,
			skipAnalytics: true,
		);

		try
		{
			$chat
				->withContextUser(self::CONTEXT_USER)
				->addUsers($toAdd, $config)
			;
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	private function deleteUsersFromChat(Im\V2\Chat $chat, array $toDelete): ProjectChatMemberSyncResult
	{
		$result = new ProjectChatMemberSyncResult();

		$config = new Relation\DeleteUserConfig(
			withMessage: false,
			skipRecent: true,
			withNotification: false,
			skipCheckReason: true,
			withoutRead: true,
		);

		foreach ($toDelete as $userId)
		{
			try
			{
				$deleteResult =
					$chat
						->withContextUser(self::CONTEXT_USER)
						->deleteUser((int)$userId, $config)
				;
				$result->addErrors($deleteResult->getErrors());
			}
			catch (\Throwable $exception)
			{
				$result->addError(new Error($exception->getMessage()));
			}
		}

		return $result;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
