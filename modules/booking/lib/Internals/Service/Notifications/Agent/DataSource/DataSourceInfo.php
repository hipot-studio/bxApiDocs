<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Main\Type\DateTime;

class DataSourceInfo extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Info;
	}

	protected function doGetBookingIdsForSend(): array
	{
		$businessRulesSql = $this->getBusinessRulesSql();
		$currentDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp)
		);
		$delayDateTime = $this->sqlHelper->addSecondsToDateTime('rns.INFO_DELAY', 'b.CREATED_AT');
		$anyMessageExistsSql = $this->getAnyMessageExistsSql(NotificationType::Info);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$businessRulesSql}
				AND {$delayDateTime} <= {$currentDateTime}
				AND NOT {$anyMessageExistsSql}
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
		$successMessageExistsSql = $this->getSuccessMessageExistsSql(NotificationType::Info);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE 1 = 1
				AND {$bookingIdFilterSql}
				AND {$businessRulesSql}
				AND NOT {$successMessageExistsSql}
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll(),
		);
	}

	private function getBusinessRulesSql(): string
	{
		$currentDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp)
		);
		$oneDayBackDateTime = $this->sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($this->currentTimestamp - Time::SECONDS_IN_DAY)
		);
		$clientAndSenderSql = $this->getClientAndSenderConditionsSql();

		return "
			b.IS_DELETED = 'N'
			AND rns.IS_INFO_ON = 'Y'
			AND b.CREATED_AT <= {$currentDateTime}
			AND b.CREATED_AT > {$oneDayBackDateTime}
			AND b.DATE_FROM > {$this->currentTimestamp}
			{$clientAndSenderSql}
		";
	}
}
