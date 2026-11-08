<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto\GroupMeetingDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Provider\GroupEventProvider;

class CalendarListService
{
	public function __construct(
		protected GroupEventProvider $groupEventProvider,
	)
	{
	}

	/**
	 * @return array<int, int> [eventId => chatId]
	 * @throws LoaderException
	 */
	public function getEventsChatsByGroupByActivityDesc(int $groupId, int $limit = 50): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		return $this->groupEventProvider->getRecentlyActiveEvents(groupId: $groupId, limit: $limit) ?? [];
	}

	/**
	 * @return array<int, int> [eventId => chatId]
	 * @throws LoaderException
	 */
	public function getEventsChatsByGroupByIdDesc(int $groupId, int $lastId, int $limit = 50): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		return $this->groupEventProvider->getEventsPaged(groupId: $groupId, limit: $limit, lastId: $lastId) ?? [];
	}

	/**
	 * Meetings are read on behalf of $userId: the calendar permission check is applied,
	 * so meetings whose title is hidden from this user are not returned.
	 *
	 * @return list<GroupMeetingDto>
	 * @throws LoaderException
	 */
	public function getFinishedProjectMeetings(
		int $groupId,
		int $userId,
		?DateTime $from = null,
		?DateTime $to = null,
		int $limit = 10,
	): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		return $this->groupEventProvider->getFinishedProjectMeetings(
			groupId: $groupId,
			userId: $userId,
			from: $from,
			to: $to,
			limit: $limit,
		);
	}
}
