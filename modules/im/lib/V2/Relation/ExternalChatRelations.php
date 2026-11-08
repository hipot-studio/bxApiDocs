<?php

namespace Bitrix\Im\V2\Relation;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterContext;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;

class ExternalChatRelations extends ChatRelations
{
	protected const NEED_ADDITIONAL_FILTER_BY_ACCESS = true;
	private array $userIdsToKeepRelations = [];

	public function filterUserIdsByAccess(array $userIds): array
	{
		$this->userIdsToKeepRelations = [];
		$userIds = parent::filterUserIdsByAccess($userIds);

		$chat = ExternalChat::getInstance($this->chatId);
		if (!$chat instanceof ExternalChat)
		{
			return $userIds;
		}

		// Phase 0 (task 718250): superadmin project chat access
		$event = new FilterUsersByAccessEvent($chat, $userIds, FilterContext::BatchRelationFilter);
		$event->send();
		if (!$event->hasResult())
		{
			return $userIds;
		}

		$this->userIdsToKeepRelations = array_fill_keys($event->getUsersToKeepRelations(), true);

		return $event->getUsersWithAccess();
	}

	protected function filterUserIdsForCleanup(array $deniedUserIds): array
	{
		$deniedUserIds = parent::filterUserIdsForCleanup($deniedUserIds);

		return array_values(array_filter(
			$deniedUserIds,
			fn (int $userId): bool => !isset($this->userIdsToKeepRelations[$userId]),
		));
	}
}
