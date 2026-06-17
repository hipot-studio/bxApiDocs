<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;

class DataSourceDelayed extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Delayed;
	}

	protected function doGetBookingIdsForSend(): array
	{
		$businessRulesSql = $this->getBusinessRulesSql();
		$messageSentDuringBookingSql = $this->getMessageSentDuringBookingSql();

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$businessRulesSql}
				AND b.DATE_FROM + rns.DELAYED_DELAY <= {$this->currentTimestamp}
				AND NOT {$messageSentDuringBookingSql}
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll(),
		);
	}

	protected function doFilterEligibleForRetry(array $bookingIds): array
	{
		$bookingIdFilterSql = $this->getBookingIdFilterSql($bookingIds);
		$businessRulesSql = $this->getBusinessRulesSql();
		$messageSentDuringBookingSql = $this->getMessageSentDuringBookingSql(onlySuccess: true);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
				AND {$businessRulesSql}
				AND NOT {$messageSentDuringBookingSql}
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll(),
		);
	}

	private function getBusinessRulesSql(): string
	{
		$oneDayBackTimestamp = $this->currentTimestamp - Time::SECONDS_IN_DAY;
		$visitStatusSql = $this->getVisitStatusUnknownSql();
		$clientAndSenderSql = $this->getClientAndSenderConditionsSql();

		return "
			b.IS_DELETED = 'N'
			AND rns.IS_DELAYED_ON = 'Y'
			AND b.DATE_FROM <= {$this->currentTimestamp}
			AND b.DATE_FROM > {$oneDayBackTimestamp}
			AND b.DATE_TO > {$this->currentTimestamp}
			AND {$visitStatusSql}
			{$clientAndSenderSql}
		";
	}

	/**
	 * Checks if a Delayed message was sent during the booking time window (DATE_FROM..DATE_TO).
	 * Prevents duplicate notifications while the client is late,
	 * while still allowing a new notification for a future booking of the same client.
	 *
	 * @param bool $onlySuccess If true, considers only successfully delivered messages.
	 */
	private function getMessageSentDuringBookingSql(bool $onlySuccess = false): string
	{
		$delayedType = $this->sqlHelper->forSql(NotificationType::Delayed->value);
		$dateFromDateTime = $this->convertTimestampToDbExpr('b.DATE_FROM');
		$dateToDateTime = $this->convertTimestampToDbExpr('b.DATE_TO');

		$statusCondition = '';
		if ($onlySuccess)
		{
			$successStatus = $this->sqlHelper->forSql(BookingMessageStatus::Success->value);
			$statusCondition = "AND STATUS = '{$successStatus}'";
		}

		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_message
				WHERE
					BOOKING_ID = b.ID
					AND NOTIFICATION_TYPE = '{$delayedType}'
					{$statusCondition}
					AND SENT_AT > {$dateFromDateTime}
					AND SENT_AT < {$dateToDateTime}
			)
		";
	}
}
