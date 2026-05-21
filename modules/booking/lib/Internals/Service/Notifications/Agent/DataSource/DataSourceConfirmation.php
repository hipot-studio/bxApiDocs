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

	protected function doGetBookingIds(): array
	{
		$currentTimestamp = time();
		$twoWeeksAheadTimestamp = $currentTimestamp + Time::SECONDS_IN_DAY * 7 * 2;
		$startSendTimestamp = "b.DATE_FROM - rns.CONFIRMATION_DELAY";

		$sql = "
			SELECT
				b.ID,
				b.TIMEZONE_FROM,
				rns.CONFIRMATION_DELAY
			FROM b_booking_booking b
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_CONFIRMATION_ON = 'Y'
			  	AND b.DATE_FROM > $currentTimestamp
			  	AND b.DATE_FROM < $twoWeeksAheadTimestamp
				AND b.IS_CONFIRMED = 'N'
				AND $startSendTimestamp <= $currentTimestamp
				AND " . $this->getVisitStatusUnknownSql() . "
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql(NotificationType::Confirmation->value) . "'
						AND
							" . $this->connection->getSqlHelper()->addSecondsToDateTime('rns.CONFIRMATION_REPETITIONS_INTERVAL', 'CREATED_AT') . "
							>= " . $this->makeDateTimeFromTimestamp($currentTimestamp) . "
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql(NotificationType::Confirmation->value) . "'
						AND CREATED_AT > " . $this->makeDateTimeFromTimestamp($startSendTimestamp) . "
					GROUP BY BOOKING_ID, NOTIFICATION_TYPE
					HAVING COUNT(1) >= 1 + rns.CONFIRMATION_REPETITIONS
				)
				" . $this->getWhereSql() . "
		";

		$bookingIds = [];
		$list = $this->connection->query($sql)->fetchAll();
		foreach ($list as $item)
		{
			$isNowWorkingHours = $this->isWorkingHours($currentTimestamp, $item['TIMEZONE_FROM']);
			$isPreciseDelay = (int)$item['CONFIRMATION_DELAY'] < $this->getPreciseDelay();
			if (!$isNowWorkingHours && !$isPreciseDelay)
			{
				continue;
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
