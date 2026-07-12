<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Provider;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

class GroupEventProvider
{
	/**
	 * @return array [eventId => chatId]
	 * @throws LoaderException
	 */
	public function getRecentlyActiveEvents(
		int $groupId,
		int $limit = 50,
	): array
	{
		return $this->fetchEvents(
			groupId: $groupId,
			order: ['TIMESTAMP_X' => 'DESC'],
			limit: $limit,
		);
	}

	public function getEventsPaged(
		int $groupId,
		int $limit = 50,
		int $lastId = 0,
	): array
	{
		return $this->fetchEvents(
			groupId: $groupId,
			order: ['ID' => 'DESC'],
			limit: $limit,
			lastId: $lastId,
		);
	}

	/**
	 * @return array<int, int|null>  [eventId => ?chatId]
	 * @throws LoaderException
	 */
	private function fetchEvents(
		int $groupId,
		array $order,
		int $limit,
		int $lastId = 0,
	): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		$arFilter = [
			'CAL_TYPE'       => 'group',
			'OWNER_ID'       => $groupId,
			'DELETED'        => 'N',
			'ACTIVE_SECTION' => 'Y',
		];

		if ($lastId > 0)
		{
			$arFilter['<ID'] = $lastId;
		}

		$getListParams = [
			'arFilter'         => $arFilter,
			'parseDescription' => false,
			'fetchAttendees'   => false,
			'checkPermissions' => false,
			'setDefaultLimit'  => false,
		];

		if (!empty($order))
		{
			$getListParams['arOrder'] = $order;
		}

		if ($limit > 0)
		{
			$getListParams['limit'] = $limit;
		}

		$rawEvents = \CCalendarEvent::GetList($getListParams);

		if (!is_array($rawEvents))
		{
			return [];
		}

		return $this->formatEvents($rawEvents);
	}

	/**
	 * @return array<int, int|null>  [eventId => ?chatId]
	 */
	private function formatEvents(array $rawEvents): array
	{
		$map = [];

		foreach ($rawEvents as $event)
		{
			$meeting = is_array($event['MEETING'] ?? null) ? $event['MEETING'] : [];
			$map[(int)$event['ID']] = (int)$meeting['CHAT_ID'] ?? null;
		}

		return $map;
	}
}
