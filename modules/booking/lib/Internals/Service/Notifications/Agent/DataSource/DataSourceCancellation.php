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

	protected function doGetBookingIds(): array
	{
		$currentTimestamp = time();

		$currentDateTime = $this->connection->getSqlHelper()->convertToDbDateTime(
			DateTime::createFromTimestamp($currentTimestamp)
		);

		$sql = "
			SELECT b.ID
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE
				b.IS_DELETED = 'Y'
				AND b.DELETION_SCENARIO IN (
					'" . $this->connection->getSqlHelper()->forSql(BookingDeletionScenario::ClientWeb->value) . "',
					'" . $this->connection->getSqlHelper()->forSql(BookingDeletionScenario::ClientYandex->value) . "'
				)
				AND b.DELETED_AT IS NOT NULL
				AND rns.IS_CANCELLATION_ON = 'Y'
				AND
					" . $this->connection->getSqlHelper()->addSecondsToDateTime(
						'rns.CANCELLATION_DELAY',
						'b.DELETED_AT'
					) . "
					<= $currentDateTime
				AND NOT  " . $this->getMessageExistsSql(NotificationType::Cancellation) . "
				" . $this->getWhereSql() . "
		";

		return array_map(
			static fn(array $row) => (int)$row['ID'],
			$this->connection->query($sql)->fetchAll()
		);
	}
}
