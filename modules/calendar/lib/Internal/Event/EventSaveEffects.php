<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use Bitrix\Calendar\Internals\EventTable;
use CCalendarSync;

final class EventSaveEffects
{
	public static function prepareDav(array &$fields, array $params, ?array $event, mixed &$result = null): ?array
	{
		$result = true;
		if (ExclusionSaveTransaction::isActive())
		{
			return [$params, $event];
		}

		$result = CCalendarSync::DoSaveToDav($fields, $params, $event ?: false);

		return null;
	}

	public static function scheduleDav(int $eventId, array $fields, ?array $pending): void
	{
		if ($pending === null || $eventId <= 0)
		{
			return;
		}
		[$params, $event] = $pending;
		ExclusionSaveTransaction::afterCommit(static function () use ($eventId, $fields, $params, $event): void {
			self::saveDav($eventId, $fields, $params, $event);
		});
	}

	private static function saveDav(int $eventId, array $fields, array $params, ?array $event): void
	{
		if (method_exists(CCalendarSync::class, 'DoSaveToDavAfterCommit'))
		{
			CCalendarSync::DoSaveToDavAfterCommit($eventId, $fields, $params, $event);

			return;
		}

		if ($event === null)
		{
			unset($fields['ID']);
		}
		else
		{
			$fields['ID'] = $eventId;
		}
		$result = CCalendarSync::DoSaveToDav($fields, $params, $event ?: false);
		if ($result !== true)
		{
			throw new \RuntimeException((string)$result);
		}
		$syncFields = array_intersect_key($fields, array_flip(['DAV_XML_ID', 'DAV_EXCH_LABEL', 'CAL_DAV_LABEL']));
		if ($syncFields !== [] && !EventTable::update($eventId, $syncFields)->isSuccess())
		{
			throw new \RuntimeException('Calendar DAV metadata update failed.');
		}
	}

	public static function clearUserCache(int $userId): void
	{
		global $CACHE_MANAGER;
		if ($userId > 0)
		{
			$CACHE_MANAGER->ClearByTag('calendar_user_' . $userId);
		}
	}
	public static function releaseOccupancyLock(int $eventId, \Bitrix\Calendar\Rooms\OccupancyLock $lock, ?array $roomIds = null): void
	{
		$release = static function () use ($eventId, $lock, $roomIds): void {
			$released = $roomIds === null ? $lock->release() : $lock->releaseRoomIds($roomIds);
			if (!$released)
			{
				ExclusionSaveTransaction::log(new \RuntimeException('Unable to release room locks.'), 'event.' . $eventId);
			}
		};
		if (ExclusionSaveTransaction::isActive())
		{
			ExclusionSaveTransaction::cleanup($release);
		}
		else
		{
			$release();
		}
	}

}
