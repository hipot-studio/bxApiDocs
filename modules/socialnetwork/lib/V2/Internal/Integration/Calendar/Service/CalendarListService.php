<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
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
}
