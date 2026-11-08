<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use Bitrix\Calendar\Rooms;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use CCalendar;
use CCalendarEvent;
use CCalendarNotify;

final class ExcludedInstanceOperation
{
	public static function execute(int $eventId, mixed $excludeDate): Main\Result
	{
		$result = new Main\Result();
		$error = static fn(string $code): Main\Result => $result->addError(
			new Main\Error(Loc::getMessage('EC_EVENT_DEL_ERROR'), 'exclude_recursion_date_' . $code),
		);
		if (ExclusionSaveTransaction::isActive())
		{
			return $error('save_failed');
		}

		$userId = (int)CCalendar::GetCurUserId();
		$eventId = (int)$eventId;
		if ($eventId <= 0)
		{
			return $error('not_found');
		}
		$event = self::getEventForExclusion($eventId);
		if ($event === null)
		{
			return $error('not_found');
		}
		if ($userId <= 0 || !CCalendarEvent::canEditExcludedInstance($event, $userId))
		{
			return $error('access_denied');
		}
		$masterId = (int)($event['PARENT_ID'] ?: $eventId);
		$event = self::getEventForExclusion($masterId);
		if ($event === null || !CCalendarEvent::CheckRecurcion($event))
		{
			return $error('not_found');
		}
		if (!CCalendarEvent::canEditExcludedInstance($event, $userId))
		{
			return $error('access_denied');
		}
		$dateTs = is_string($excludeDate) ? CCalendar::Timestamp($excludeDate, false, true) : 0;
		if (!$dateTs || !in_array($excludeDate, [CCalendar::Date($dateTs, false), CCalendar::Date($dateTs)], true))
		{
			return $error('invalid');
		}

		$excludeDate = CCalendar::Date($dateTs, false);
		$connection = Main\Application::getConnection();
		$lockName = 'calendar.event.exclude.' . $masterId;
		try
		{
			if (!$connection->lock($lockName, 5))
			{
				return $error('locked');
			}
		}
		catch (\Throwable $exception)
		{
			ExclusionSaveTransaction::log($exception, 'exclude.' . $masterId);

			return $error('locked');
		}
		$occupancyLock = new Rooms\OccupancyLock();
		$locksReleased = false;
		$releaseLocks = static function () use ($occupancyLock, $connection, $lockName, $masterId, &$locksReleased): void {
			if ($locksReleased)
			{
				return;
			}
			$locksReleased = true;
			foreach ([static fn() => $occupancyLock->release(), static fn() => $connection->unlock($lockName)] as $release)
			{
				try
				{
					if ($release() === false)
					{
						ExclusionSaveTransaction::log(new \RuntimeException('Unable to release the exclusion lock.'), 'exclude.' . $masterId);
					}
				}
				catch (\Throwable $exception)
				{
					ExclusionSaveTransaction::log($exception, 'exclude.' . $masterId);
				}
			}
		};
		try
		{
			$occupancyLock->acquire(Rooms\OccupancyLock::resolveRoomIds($event['LOCATION'], $event['LOCATION']));
			$event = self::getEventForExclusion($masterId);
			if ($event === null || !CCalendarEvent::CheckRecurcion($event))
			{
				return $error('not_found');
			}
			if (!CCalendarEvent::canEditExcludedInstance($event, $userId))
			{
				return $error('access_denied');
			}
			if (!$occupancyLock->hasAcquiredRoomIds(Rooms\OccupancyLock::resolveRoomIds($event['LOCATION'], $event['LOCATION'])))
			{
				return $error('locked');
			}
			try
			{
				CCalendarEvent::checkExcludedInstanceLocationPermission($event['LOCATION']);
			}
			catch (Rooms\OccupancyCheckerException)
			{
				return $error('access_denied');
			}
			if (!self::isDateInExcludedSeries($event, $excludeDate))
			{
				return $error('invalid');
			}
			$result->setData(['eventId' => $masterId, 'excludeDate' => $excludeDate, 'changed' => false]);
			$excludeDates = CCalendarEvent::GetExDate($event['EXDATE']);
			if (in_array($excludeDate, $excludeDates, true))
			{
				return $result;
			}
			$excludeDates[] = $excludeDate;
			ExclusionSaveTransaction::run(static function () use (
				$event, $excludeDate, $excludeDates, $userId, $occupancyLock, $releaseLocks,
			): void {
				ExclusionSaveTransaction::cleanup($releaseLocks);
				ExclusionSaveTransaction::cleanup(static function () use ($event): void {
					CCalendar::ClearCache(['event_list', 'section_list']);
					foreach ($event['ATTENDEE_LIST'] ?? [] as $attendee)
					{
						EventSaveEffects::clearUserCache((int)$attendee['id']);
					}
				});
				$fields = [
					'ID' => $event['ID'],
					'DATE_FROM' => $event['DATE_FROM'],
					'DATE_TO' => $event['DATE_TO'],
					'EXDATE' => CCalendarEvent::SetExDate($excludeDates),
				];
				if ($event['IS_MEETING'] === false)
				{
					$fields['ATTENDEES'] = $event['ATTENDEES'] ?? array_column($event['ATTENDEE_LIST'] ?? [], 'id');
				}
				$saveResult = CCalendar::SaveEvent([
					'arFields' => $fields,
					'userId' => $userId,
					...ExclusionSaveTransaction::saveParameters(),
					'excludedInstanceContext' => new ExcludedInstanceMutation((int)$event['ID'], $excludeDate, $event),
					'occupancyLock' => $occupancyLock,
					'silentErrorMode' => false,
					'recursionEditMode' => 'skip',
					'editParentEvents' => true,
					'sendInvitations' => false,
					'sendEditNotification' => false,
				]);
				$savedId = is_array($saveResult) ? (int)($saveResult['id'] ?? 0) : (int)$saveResult;
				if ($savedId !== (int)$event['ID'])
				{
					throw new \RuntimeException('Unable to save the excluded instance.');
				}
				ExclusionSaveTransaction::afterCommit(static function () use ($event, $excludeDate, $userId): void {
					self::notifyExcludedInstance($event, $excludeDate, $userId);
				});
			});
			$result->setData(['eventId' => $masterId, 'excludeDate' => $excludeDate, 'changed' => true]);

			return $result;
		}
		catch (Rooms\OccupancyLockedException)
		{
			return $error('locked');
		}
		catch (\Throwable $exception)
		{
			ExclusionSaveTransaction::log($exception, 'exclude.' . $masterId);

			return $error('save_failed');
		}
		finally
		{
			$releaseLocks();
		}
	}

	private static function getEventForExclusion(int $eventId): ?array
	{
		$events = CCalendarEvent::GetList([
			'arFilter' => ['ID' => $eventId, 'DELETED' => 'N'],
			'parseRecursion' => false,
			'fetchAttendees' => true,
			'checkPermissions' => false,
			'setDefaultLimit' => false,
			'skipCache' => true,
		]);

		return $events[0] ?? null;
	}

	private static function isDateInExcludedSeries(array $event, string $date): bool
	{
		if ((CCalendarEvent::ParseRRULE($event['RRULE'])['FREQ'] ?? null) === 'WEEKLY')
		{
			return WeeklyRecurrenceMembership::contains($event, $date);
		}

		$event['EXDATE'] = '';
		$instances = [];
		$from = CCalendar::Timestamp($date, false, false);
		CCalendarEvent::ParseRecursion($instances, $event, [
			'userId' => CCalendar::GetCurUserId(),
			'fromLimit' => null,
			'fromLimitTs' => $from - CCalendar::GetDayLen(),
			'toLimitTs' => $from + 2 * CCalendar::GetDayLen(),
			'loadLimit' => null,
			'instanceCount' => false,
			'checkPermission' => false,
			'preciseLimits' => false,
		]);
		foreach ($instances as $instance)
		{
			if (CCalendar::Date(CCalendar::Timestamp($instance['DATE_FROM'], false, false), false) === $date)
			{
				return true;
			}
		}

		return false;
	}

	private static function notifyExcludedInstance(array $event, string $excludeDate, int $userId): void
	{
		$excludeDateTs = CCalendar::Timestamp($excludeDate, false, false);
		if ($event['DT_SKIP_TIME'] !== 'Y')
		{
			$excludeDate = CCalendar::Date(CCalendar::DateWithNewTime(CCalendar::Timestamp($event['DATE_FROM']), $excludeDateTs));
		}
		foreach ($event['ATTENDEE_LIST'] ?? [] as $attendee)
		{
			if ($attendee['status'] === 'Y')
			{
				CCalendarNotify::Send([
					'mode' => 'cancel_this',
					'name' => $event['NAME'],
					'from' => $excludeDate,
					'guestId' => $attendee['id'],
					'eventId' => $event['PARENT_ID'],
					'userId' => $userId,
					'fields' => $event,
				]);
			}
		}
	}

}
