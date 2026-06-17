<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Entity\Booking\BookingDeletionScenario;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Main\Type\DateTime;

class DataSourceCancellation extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Cancellation;
	}

	protected function doGetBookingIdsForSend(): array
	{
		$businessRulesSql = $this->getBusinessRulesSql();
		$currentDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp)
		);
		$delayDateTime = $this->sqlHelper->addSecondsToDateTime('rns.CANCELLATION_DELAY', 'b.DELETED_AT');
		$anyMessageExistsSql = $this->getAnyMessageExistsSql(NotificationType::Cancellation);

		$sql = "
			SELECT
				b.ID,
				b.TIMEZONE_FROM
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$businessRulesSql}
				AND {$delayDateTime} <= {$currentDateTime}
				AND NOT {$anyMessageExistsSql}
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
				b.TIMEZONE_FROM
			FROM b_booking_booking b
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
		";

		return $this->filterBySendTimeRules(
			$this->connection->query($sql)->fetchAll(),
		);
	}

	private function getBusinessRulesSql(): string
	{
		$clientWeb = $this->sqlHelper->forSql(BookingDeletionScenario::ClientWeb->value);
		$clientYandex = $this->sqlHelper->forSql(BookingDeletionScenario::ClientYandex->value);
		$clientAndSenderSql = $this->getClientAndSenderConditionsSql();

		return "
			b.IS_DELETED = 'Y'
			AND b.DELETION_SCENARIO IN ('{$clientWeb}', '{$clientYandex}')
			AND b.DELETED_AT IS NOT NULL
			AND rns.IS_CANCELLATION_ON = 'Y'
			{$clientAndSenderSql}
		";
	}

	/**
	 * Filters bookings by send time rules.
	 * Allows sending only during working hours in the booking's timezone.
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
			if (!$isNowWorkingHours)
			{
				continue;
			}

			$bookingIds[] = (int)$item['ID'];
		}

		return $bookingIds;
	}
}
