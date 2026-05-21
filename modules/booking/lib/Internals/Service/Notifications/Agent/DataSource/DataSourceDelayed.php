<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;

class DataSourceDelayed extends BaseDataSource
{
	protected function getNotificationType(): NotificationType
	{
		return NotificationType::Delayed;
	}

	protected function doGetBookingIds(): array
	{
		$currentTimestamp = time();
		$oneDayBehindTimestamp = $currentTimestamp - Time::SECONDS_IN_DAY;

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_DELAYED_ON = 'Y'
			  	AND b.DATE_FROM <= $currentTimestamp
			  	AND b.DATE_FROM > $oneDayBehindTimestamp
			  	AND b.DATE_TO > $currentTimestamp
				AND b.DATE_FROM + rns.DELAYED_DELAY <= $currentTimestamp
				AND " . $this->getVisitStatusUnknownSql() . "
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql(NotificationType::Delayed->value) . "'
						AND CREATED_AT > " . $this->makeDateTimeFromTimestamp('b.DATE_FROM') . "
						AND CREATED_AT < " . $this->makeDateTimeFromTimestamp('b.DATE_TO') . "
				)
				" . $this->getWhereSql() . "
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll()
		);
	}
}
