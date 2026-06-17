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

	protected function doGetBookingIdsForSend(): array
	{
		$businessRulesSql = $this->getBusinessRulesSql();
		$oneHourBackDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp - Time::SECONDS_IN_HOUR)
		);
		$infoType = $this->sqlHelper->forSql(NotificationType::Info->value);
		$reminderType = $this->sqlHelper->forSql(NotificationType::Reminder->value);
		$morningDelay = (int)ReminderNotificationDelay::Morning->value;
		$oneDayBackDateTime = $this->sqlHelper->addSecondsToDateTime(-Time::SECONDS_IN_DAY);
		$delayBackDateTime = $this->sqlHelper->addSecondsToDateTime('-' . 'rns.REMINDER_DELAY');

		$sql = "
			SELECT
				b.ID,
				b.DATE_FROM,
				b.TIMEZONE_FROM,
				rns.REMINDER_DELAY
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$businessRulesSql}
				AND NOT (
					b.CREATED_AT > {$oneHourBackDateTime}
					AND EXISTS (
						SELECT 1
						FROM b_booking_booking_message
						WHERE
							BOOKING_ID = b.ID
							AND NOTIFICATION_TYPE = '{$infoType}'
					)
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '{$reminderType}'
						AND
							CASE WHEN (rns.REMINDER_DELAY = {$morningDelay}) THEN
								SENT_AT > {$oneDayBackDateTime}
							ELSE
								SENT_AT > {$delayBackDateTime}
							END
				)
		";

		return $this->filterBySendTimeRules(
			$this->connection->query($sql)->fetchAll(),
		);
	}

	protected function doFilterEligibleForRetry(array $bookingIds): array
	{
		$bookingIdFilterSql = $this->getBookingIdFilterSql($bookingIds);
		$businessRulesSql = $this->getBusinessRulesSql();
		$oneHourBackDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp - Time::SECONDS_IN_HOUR)
		);
		$successInfoExistsSql = $this->getSuccessMessageExistsSql(NotificationType::Info);
		$successReminderExistsSql = $this->getSuccessMessageExistsSql(NotificationType::Reminder);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
				AND {$businessRulesSql}
				AND NOT (
					b.CREATED_AT > {$oneHourBackDateTime}
					AND {$successInfoExistsSql}
				)
				AND NOT {$successReminderExistsSql}
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll(),
		);
	}

	protected function doFilterReadyToSendNow(array $bookingIds): array
	{
		$bookingIdFilterSql = $this->getBookingIdFilterSql($bookingIds);

		$sql = "
			SELECT
				b.ID,
				b.DATE_FROM,
				b.TIMEZONE_FROM,
				rns.REMINDER_DELAY
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
		";

		return $this->filterBySendTimeRules(
			$this->connection->query($sql)->fetchAll(),
		);
	}

	private function getBusinessRulesSql(): string
	{
		$twoWeeksAheadTimestamp = $this->currentTimestamp + Time::SECONDS_IN_DAY * 7 * 2;
		$visitStatusSql = $this->getVisitStatusUnknownSql();
		$clientAndSenderSql = $this->getClientAndSenderConditionsSql();

		return "
			b.IS_DELETED = 'N'
			AND rns.IS_REMINDER_ON = 'Y'
			AND b.DATE_FROM > {$this->currentTimestamp}
			AND b.DATE_FROM < {$twoWeeksAheadTimestamp}
			AND {$visitStatusSql}
			{$clientAndSenderSql}
		";
	}

	/**
	 * Filters bookings by send time rules.
	 * For morning scenario: allows sending on the same day as booking
	 * or when there's not enough time left before overnight gap.
	 * For precise delay (less than 1 day): allows sending at any time
	 * when the delay has elapsed.
	 * Otherwise: allows sending only during working hours.
	 */
	private function filterBySendTimeRules(array $rows): array
	{
		$bookingIds = [];
		foreach ($rows as $item)
		{
			$isNowWorkingHours = $this->workingTimeService->isWithinWorkingHoursAt(
				$this->currentTimestamp,
				$item['TIMEZONE_FROM'],
			);
			$isSameDay = Time::isSameDay(
				$this->currentTimestamp,
				(int)$item['DATE_FROM'],
				$item['TIMEZONE_FROM'],
			);
			$isMorningScenario = (int)$item['REMINDER_DELAY'] === ReminderNotificationDelay::Morning->value;
			$isPreciseDelay = (
				!$isMorningScenario
				&& (int)$item['REMINDER_DELAY'] < $this->getPreciseDelayThreshold()
			);

			if (!$isNowWorkingHours && !$isPreciseDelay)
			{
				continue;
			}

			if ($isMorningScenario)
			{
				$overnightGap = $this->workingTimeService->getOvernightGapInSeconds();
				$noTimeLeft = (int)$item['DATE_FROM'] - $this->currentTimestamp <= $overnightGap;

				if (!$isSameDay && !$noTimeLeft)
				{
					continue;
				}
			}
			else
			{
				$isTimeToSend = (int)$item['DATE_FROM'] - (int)$item['REMINDER_DELAY'] <= $this->currentTimestamp;
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
	 * Returns the threshold in seconds below which a delay
	 * is considered precise enough to send at any time,
	 * ignoring working hours restrictions.
	 */
	private function getPreciseDelayThreshold(): int
	{
		return Time::SECONDS_IN_DAY;
	}
}
