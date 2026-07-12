<?php

namespace Bitrix\Calendar\Access\Rule\Traits;

use Bitrix\Calendar\Access\AccessibleEvent;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Internal\Integration\Socialnetwork\CollabService;
use Bitrix\Calendar\Util;
use Bitrix\Main\DI\ServiceLocator;

trait CollabTrait
{
	private function isCollaberHasEditAccess(AccessibleEvent $item, int $userId): bool
	{
		if (!Util::isCollabUser($userId))
		{
			return true;
		}

		if (
			$item->getOwnerId() === $userId
			|| $item->getCreatorId() === $userId
			|| $item->hasAttendee($userId)
		)
		{
			return true;
		}

		if ($item->getEventType() === Dictionary::EVENT_TYPE['shared_collab'])
		{
			return false;
		}

		// Project events: restriction applies only when the section's group
		// actually has external users. Under the new_projects schema a TYPE='collab'
		// group without HAS_COLLABERS='Y' is treated as a regular project — no
		// collab-restrictions on its events.
		return !ServiceLocator::getInstance()
			->get(CollabService::class)
			->isSectionProjectHasCollabers($item->getSectionId())
		;
	}
}
