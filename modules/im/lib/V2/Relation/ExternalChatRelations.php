<?php

namespace Bitrix\Im\V2\Relation;

use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterContext;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;

class ExternalChatRelations extends ChatRelations
{
	protected const NEED_ADDITIONAL_FILTER_BY_ACCESS = true;

	public function filterUserIdsByAccess(array $userIds): array
	{
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

		return $event->getUsersWithAccess();
	}
}