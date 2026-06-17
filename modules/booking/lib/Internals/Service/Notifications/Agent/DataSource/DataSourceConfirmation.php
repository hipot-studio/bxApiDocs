<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;

class DataSourceConfirmation extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Confirmation;
	}

	protected function doGetBookingIdsForSend(): array
	{
		$businessRulesSql = $this->getBusinessRulesSql();
		$confirmationType = $this->sqlHelper->forSql(NotificationType::Confirmation->value);
		$startSendTimestamp = "b.DATE_FROM - rns.CONFIRMATION_DELAY";
		$currentDateTime = $this->convertTimestampToDbExpr($this->currentTimestamp);
		$startSendDateTime = $this->convertTimestampToDbExpr($startSendTimestamp);
		$repetitionsInterval = $this->sqlHelper->addSecondsToDateTime(
			'rns.CONFIRMATION_REPETITIONS_INTERVAL',
			'SENT_AT',
		);

		$sql = "
			SELECT
				b.ID,
				b.TIMEZONE_FROM,
				rns.CONFIRMATION_DELAY
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$businessRulesSql}
				AND {$startSendTimestamp} <= {$this->currentTimestamp}
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '{$confirmationType}'
						AND {$repetitionsInterval} >= {$currentDateTime}
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '{$confirmationType}'
						AND SENT_AT > {$startSendDateTime}
					GROUP BY BOOKING_ID, NOTIFICATION_TYPE
					HAVING COUNT(1) >= 1 + rns.CONFIRMATION_REPETITIONS
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

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
				AND {$businessRulesSql}
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
				b.TIMEZONE_FROM,
				rns.CONFIRMATION_DELAY
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
			AND rns.IS_CONFIRMATION_ON = 'Y'
			AND b.DATE_FROM > {$this->currentTimestamp}
			AND b.DATE_FROM < {$twoWeeksAheadTimestamp}
			AND b.IS_CONFIRMED = 'N'
			AND {$visitStatusSql}
			{$clientAndSenderSql}
		";
	}

	/**
	 * Filters bookings by send time rules.
	 * Allows sending if it's currently working hours in the booking's timezone
	 * or if the confirmation delay is precise (less than 1 day).
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
			$isPreciseDelay = (int)$item['CONFIRMATION_DELAY'] < $this->getPreciseDelayThreshold();
			if (!$isNowWorkingHours && !$isPreciseDelay)
			{
				continue;
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
