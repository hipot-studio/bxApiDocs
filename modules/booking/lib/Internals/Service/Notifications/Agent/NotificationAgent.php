<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingVisitStatus;
use Bitrix\Booking\Entity\Enum\Notification\ReminderNotificationDelay;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\SqlHelper;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\DB;

class NotificationAgent
{
	private static Connection $connection;
	private static DB\SqlHelper $sqlHelper;
	private static SqlHelper $localSqlHelper;

	public static function execute(): string
	{
		self::$connection = Application::getConnection();
		self::$sqlHelper = self::$connection->getSqlHelper();
		self::$localSqlHelper = new SqlHelper();

		self::process(
			self::$connection->query(self::getInfoSql())->fetchAll(),
			NotificationType::Info
		);
		self::process(
			self::$connection->query(self::getConfirmationSql())->fetchAll(),
			NotificationType::Confirmation
		);
		self::process(
			self::$connection->query(self::getReminderSql())->fetchAll(),
			NotificationType::Reminder
		);
		self::process(
			self::$connection->query(self::getDelayedSql())->fetchAll(),
			NotificationType::Delayed
		);

		return '\\' . self::class . '::execute();';
	}

	private static function process(array $bookingIdRows, NotificationType $notificationType): void
	{
		(new BookingHandlerService())->handleBookings(
			array_column($bookingIdRows, 'ID'),
			static function (Booking $booking) use ($notificationType) {
				Container::getMessageSender()->send($booking, $notificationType);
			}
		);
	}

	private static function getInfoSql(): string
	{
		$currentTimestamp = time();

		$currentDateTime = self::$sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp($currentTimestamp)
		);
		$oneDayBackDateTime = self::$sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(
				$currentTimestamp - Time::SECONDS_IN_DAY
			)
		);

		return "
			SELECT b.ID
			FROM b_booking_booking b
			" . self::getResourceSettingsJoinSql() . "
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_INFO_ON = 'Y'
				AND b.CREATED_AT <= $currentDateTime
				AND b.CREATED_AT > $oneDayBackDateTime
			  	AND b.DATE_FROM > $currentTimestamp
				AND
					" . self::$sqlHelper->addSecondsToDateTime(
						'rns.INFO_DELAY',
						'b.CREATED_AT'
					) . "
					<= $currentDateTime
				AND NOT  " .  self::getMessageExistsSql(NotificationType::Info) . "
				AND " . self::getClientExistsSql() . "
		";
	}

	private static function getConfirmationSql(): string
	{
		$currentTimestamp = time();
		$twoWeeksAheadTimestamp = $currentTimestamp + Time::SECONDS_IN_DAY * 7 * 2;

		$startSendTimestamp = "b.DATE_FROM - rns.CONFIRMATION_DELAY";

		return "
			SELECT b.ID
			FROM b_booking_booking b
			" . self::getResourceSettingsJoinSql() . "
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_CONFIRMATION_ON = 'Y'
			  	AND b.DATE_FROM > $currentTimestamp
			  	AND b.DATE_FROM < $twoWeeksAheadTimestamp
				AND b.IS_CONFIRMED = 'N'
				AND $startSendTimestamp <= $currentTimestamp
				AND (
					" . self::getIsNowWorkingHoursSql($currentTimestamp) . "
					OR rns.CONFIRMATION_DELAY < " . self::getPreciseDelayForConfirmationAndDelayed() . "
				)
				AND " . self::getVisitStatusUnknownSql() . "
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql(NotificationType::Confirmation->value) . "'
						AND
							" . self::$sqlHelper->addSecondsToDateTime(
								'rns.CONFIRMATION_REPETITIONS_INTERVAL',
								'CREATED_AT'
							) . "
							>= " . self::$localSqlHelper->makeDateTimeFromTimestamp($currentTimestamp) . "
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql(NotificationType::Confirmation->value) . "'
						AND CREATED_AT > " . self::$localSqlHelper->makeDateTimeFromTimestamp($startSendTimestamp) . "
					GROUP BY BOOKING_ID, NOTIFICATION_TYPE
					HAVING COUNT(1) >= 1 + rns.CONFIRMATION_REPETITIONS
				)
				AND " . self::getClientExistsSql() . "
		";
	}

	private static function getReminderSql(): string
	{
		$currentTimestamp = time();
		$twoWeeksAheadTimestamp = $currentTimestamp + Time::SECONDS_IN_DAY * 7 * 2;
		$oneHourBehindDateTime = self::$sqlHelper->convertToDbDateTime(
			DateTime::createFromTimestamp(
				$currentTimestamp - Time::SECONDS_IN_HOUR
			)
		);

		$isMorningScenario = "
			rns.REMINDER_DELAY = "
			. self::$sqlHelper->forSql(ReminderNotificationDelay::Morning->value)
		;
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

		return "
			SELECT b.ID
			FROM b_booking_booking b
			" . self::getResourceSettingsJoinSql() . "
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_REMINDER_ON = 'Y'
				AND b.DATE_FROM > $currentTimestamp
			  	AND b.DATE_FROM < $twoWeeksAheadTimestamp
				AND
					CASE WHEN ($isMorningScenario) THEN
						" . self::getIsSameDaySql($currentTimestamp) ." = 1
						OR b.DATE_FROM - $currentTimestamp <= $overnightGap
						ELSE
							b.DATE_FROM - rns.REMINDER_DELAY <= $currentTimestamp
						END
				AND (
					" . self::getIsNowWorkingHoursSql($currentTimestamp) . "
					OR (
						NOT $isMorningScenario
						AND rns.REMINDER_DELAY < " . self::getPreciseDelayForConfirmationAndDelayed() . "
					)
				)
				AND " . self::getVisitStatusUnknownSql() . "
				AND NOT (
					b.CREATED_AT > $oneHourBehindDateTime
					AND EXISTS (
						SELECT 1
						FROM b_booking_booking_message
						WHERE
							BOOKING_ID = b.ID
							AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql(NotificationType::Info->value) . "'	
					)
				)
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql(NotificationType::Reminder->value) . "'
						AND
							CASE WHEN ($isMorningScenario) THEN
								CREATED_AT > " . self::$sqlHelper->addSecondsToDateTime(
									'-' . Time::SECONDS_IN_DAY
								) . "
							ELSE
								CREATED_AT > " . self::$sqlHelper->addSecondsToDateTime(
									'-' . 'rns.REMINDER_DELAY'
								) . "
							END
				)
				AND " . self::getClientExistsSql() . "
		";
	}

	private static function getDelayedSql(): string
	{
		$currentTimestamp = time();
		$oneDayBehindTimestamp = $currentTimestamp - Time::SECONDS_IN_DAY;

		return "
			SELECT b.ID
			FROM b_booking_booking b
			" . self::getResourceSettingsJoinSql() . "
			WHERE
				b.IS_DELETED = 'N'
				AND rns.IS_DELAYED_ON = 'Y'
			  	AND b.DATE_FROM <= $currentTimestamp
			  	AND b.DATE_FROM > $oneDayBehindTimestamp
			  	AND b.DATE_TO > $currentTimestamp
				AND b.DATE_FROM + rns.DELAYED_DELAY <= $currentTimestamp
				AND " . self::getVisitStatusUnknownSql() . "
				AND NOT EXISTS (
					SELECT 1
					FROM b_booking_booking_message
					WHERE
						BOOKING_ID = b.ID
						AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql(NotificationType::Delayed->value) . "'
						AND CREATED_AT > " . self::$localSqlHelper->makeDateTimeFromTimestamp('b.DATE_FROM') . "
						AND CREATED_AT < " . self::$localSqlHelper->makeDateTimeFromTimestamp('b.DATE_TO') . "
				)
				AND " . self::getClientExistsSql() . "
		";
	}

	private static function getClientExistsSql(): string
	{
		$entityTypeBooking = Application::getConnection()->getSqlHelper()->forSql(
			EntityType::Booking->value
		);

		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_client
				WHERE
					ENTITY_ID = b.ID
					AND ENTITY_TYPE = '$entityTypeBooking'
			)
		";
	}

	private static function getMessageExistsSql(NotificationType $notificationType): string
	{
		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_message
				WHERE
					BOOKING_ID = b.ID
					AND NOTIFICATION_TYPE = '" . self::$sqlHelper->forSql($notificationType->value) . "'
				)
		";
	}

	private static function getResourceSettingsJoinSql(): string
	{
		return "
			JOIN b_booking_booking_resource bbr ON bbr.BOOKING_ID = b.ID AND bbr.IS_PRIMARY = 'Y'
			JOIN b_booking_resource_notification_settings rns ON rns.RESOURCE_ID = bbr.RESOURCE_ID 
		";
	}

	private static function getVisitStatusUnknownSql(): string
	{
		$unknownVisitStatus = self::$sqlHelper->forSql(BookingVisitStatus::Unknown->value);

		return "
			b.VISIT_STATUS = '$unknownVisitStatus'
		";
	}

	private static function getIsNowWorkingHoursSql(int $currentTimestamp): string
	{
		$currentHour = "
			EXTRACT(HOUR FROM " . self::$sqlHelper->addSecondsToDateTime(
				'b.TIMEZONE_FROM_OFFSET',
				self::$localSqlHelper->makeDateTimeFromTimestamp($currentTimestamp)
			) . ")
		";

		return "
			(
				$currentHour >= " . Time::DAYTIME_START_HOUR . "
				AND " . $currentHour . " < " . Time::DAYTIME_END_HOUR . "
			)
		";
	}

	private static function getIsSameDaySql(int $currentTimestamp): string
	{
		return "
			CASE WHEN (
				EXTRACT(DAY FROM " . self::$sqlHelper->addSecondsToDateTime(
					'b.TIMEZONE_FROM_OFFSET',
					self::$localSqlHelper->makeDateTimeFromTimestamp('b.DATE_FROM')
				) . ")
				=
				EXTRACT(DAY FROM " . self::$sqlHelper->addSecondsToDateTime(
					'b.TIMEZONE_FROM_OFFSET',
				self::$localSqlHelper->makeDateTimeFromTimestamp($currentTimestamp)
				) . ")
			)
			THEN 1
			ELSE 0
			END
		";
	}

	/**
	 * If the delay is less than returned we consider it to be precise
	 * and therefore can send notification at any time (not only in working time)
	 *
	 * @return int
	 */
	private static function getPreciseDelayForConfirmationAndDelayed(): int
	{
		return Time::SECONDS_IN_DAY;
	}
}
