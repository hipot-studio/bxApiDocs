<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Sharing;

use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Access\SectionAccessController;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Internals\SectionTable;
use Bitrix\Calendar\Integration\Crm\UserPermissionsHandler;

class DeletedSharedEventAccessChecker
{
	public function canView(int $userId, Link\EventLink $eventLink, ?Link\Link $parentLink): bool
	{
		if ($parentLink instanceof Link\CrmDealLink)
		{
			return UserPermissionsHandler::canReadDeal($userId, $parentLink->getObjectId());
		}

		if ($parentLink instanceof Link\GroupLink)
		{
			if ($this->isGroupEventMember($userId, $parentLink->getGroupId()))
			{
				return true;
			}

			return $this->isEventMember($userId, $eventLink);
		}

		if ($parentLink instanceof Link\UserLink)
		{
			foreach ($parentLink->getMembers() as $member)
			{
				if ($member->getId() === $userId)
				{
					return true;
				}
			}
		}

		return $this->isEventMember($userId, $eventLink);
	}

	private function isEventMember(int $userId, Link\EventLink $eventLink): bool
	{
		$eventMembers = [$eventLink->getHostId(), $eventLink->getOwnerId()];

		return in_array($userId, $eventMembers, true);
	}

	private function isGroupEventMember(int $userId, int $groupId): bool
	{
		$section =
			SectionTable::query()
				->setSelect(['ID'])
				->where('OWNER_ID', $groupId)
				->where('CAL_TYPE', Dictionary::CALENDAR_TYPE['group'])
				->where('ACTIVE', 'Y')
				->setLimit(1)
				->exec()
				->fetch()
		;

		if (!$section)
		{
			return false;
		}

		return SectionAccessController::can(
			$userId,
			ActionDictionary::ACTION_SECTION_EVENT_VIEW_FULL,
			(int)($section['ID'] ?? 0),
		);
	}
}
