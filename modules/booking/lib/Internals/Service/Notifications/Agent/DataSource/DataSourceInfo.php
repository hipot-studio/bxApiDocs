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

	protected function doGetBookingIds(): array
	{
		$currentTimestamp = time();

		$currentDateTime = $this->connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp($currentTimestamp)
		);
		$oneDayBackDateTime = $this->connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp(
				$currentTimestamp - Time::SECONDS_IN_DAY
			)
		);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_INFO_ON = 'Y'
				AND b.CREATED_AT <= $currentDateTime
				AND b.CREATED_AT > $oneDayBackDateTime
			  	AND b.DATE_FROM > $currentTimestamp
				AND
					" . $this->connection->getSqlHelper()->addSecondsToDateTime(
				'rns.INFO_DELAY',
				'b.CREATED_AT'
			) . "
					<= $currentDateTime
				AND NOT  " . $this->getMessageExistsSql(NotificationType::Info) . "
				" . $this->getWhereSql() . "
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll()
		);
	}
}
