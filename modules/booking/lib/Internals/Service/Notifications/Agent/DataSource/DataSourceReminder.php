<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Entity\Enum\Notification\ReminderNotificationDelay;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Main\Type\DateTime;

class DataSourceReminder extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Reminder;
	}

	protected function doGetBookingIds(): array
	{
		$currentTimestamp = time();
		$twoWeeksAheadTimestamp = $currentTimestamp + Time::SECONDS_IN_DAY * 7 * 2;
		$oneHourBehindDateTime = $this->connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(
				$currentTimestamp - Time::SECONDS_IN_HOUR
			)
		);

		$sql = "
			SELECT
				b.ID,
				b.DATE_FROM,
				b.TIMEZONE_FROM,
				rns.REMINDER_DELAY
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_REMINDER_ON = 'Y'
				AND b.DATE_FROM > $currentTimestamp
			  	AND b.DATE_FROM < $twoWeeksAheadTimestamp
				AND " . $this->getVisitStatusUnknownSql() . "
				AND NOT (
					b.CREATED_AT > $oneHourBehindDateTime
					AND EXISTS (
						SELECT 1
						FROM b_booking_booking_message
						WHERE
							BOOKING_ID = b.ID
							AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql(NotificationType::Info->value) . "'
					)
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql(NotificationType::Reminder->value) . "'
						AND
							CASE WHEN (rns.REMINDER_DELAY = " . $this->connection->getSqlHelper()->forSql(ReminderNotificationDelay::Morning->value) . ") THEN
								CREATED_AT > " . $this->connection->getSqlHelper()->addSecondsToDateTime(
			'-' . Time::SECONDS_IN_DAY
		) . "
							ELSE
								CREATED_AT > " . $this->connection->getSqlHelper()->addSecondsToDateTime(
			'-' . 'rns.REMINDER_DELAY'
		) . "
							END
				)
			" . $this->getWhereSql() . "
		";

		$bookingIds = [];
		$list = $this->connection->query($sql)->fetchAll();
		foreach ($list as $item)
		{
			$isNowWorkingHours = $this->isWorkingHours($currentTimestamp, $item['TIMEZONE_FROM']);
			$isSameDay = $this->isSameDay($currentTimestamp, (int)$item['DATE_FROM'], $item['TIMEZONE_FROM']);
			$isMorningScenario = (int)$item['REMINDER_DELAY'] === ReminderNotificationDelay::Morning->value;
			$isPreciseDelay = (
				!$isMorningScenario
				&& (int)$item['REMINDER_DELAY'] < $this->getPreciseDelay()
			);

			if (!$isNowWorkingHours && !$isPreciseDelay)
			{
				continue;
			}

			if ($isMorningScenario)
			{
				$overnightGap =
					(
						Time::HOURS_IN_DAY -
						(
							Time::DAYTIME_END_HOUR
							- Time::DAYTIME_START_HOUR
							- 1
						)
					) * Time::SECONDS_IN_HOUR
				;
				$noTimeLeft = (int)$item['DATE_FROM'] - $currentTimestamp <= $overnightGap;

				if (!$isSameDay && !$noTimeLeft)
				{
					continue;
				}
			}
			else
			{
				$isTimeToSend = (int)$item['DATE_FROM'] - (int)$item['REMINDER_DELAY'] <= $currentTimestamp;
				if (!$isTimeToSend)
				{
					continue;
				}
			}

			$bookingIds[] = (int)$item['ID'];
		}

		return $bookingIds;
	}

	/**
	 * If the delay is less than returned we consider it to be precise
	 * and therefore can send notification at any time (not only in working time)
	 *
	 * @return int
	 */
	private function getPreciseDelay(): int
	{
		return Time::SECONDS_IN_DAY;
	}
}
