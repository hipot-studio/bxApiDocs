<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Entity\Booking\BookingVisitStatus;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BaseMessageSender;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSenderNotification;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\WorkingTimeService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\DB\SqlHelper;

abstract class BaseDataSource
{
	private const PENDING_LOCK_WINDOW_SECONDS = 15 * 60;

	protected WorkingTimeService $workingTimeService;
	private MessageSenderNotification $senderNotification;
	protected Connection $connection;
	protected SqlHelper $sqlHelper;
	protected int $currentTimestamp;

	public function __construct()
	{
		$this->workingTimeService = Container::getWorkingTimeService();
		$this->senderNotification = Container::getMessageSenderNotification();

		$this->connection = Application::getConnection();
		$this->sqlHelper = $this->connection->getSqlHelper();
		$this->currentTimestamp = time();
	}

	public static function make(NotificationType $notificationType): self|null
	{
		return match ($notificationType)
		{
			NotificationType::Info => new DataSourceInfo(),
			NotificationType::Confirmation => new DataSourceConfirmation(),
			NotificationType::Reminder => new DataSourceReminder(),
			NotificationType::Delayed => new DataSourceDelayed(),
			NotificationType::Cancellation => new DataSourceCancellation(),
			default => null,
		};
	}

	/**
	 * Returns booking IDs that need a notification of this type right now.
	 * Used by NotificationAgent for the initial send flow.
	 *
	 * @return int[]
	 */
	public function getBookingIdsForSend(): array
	{
		$supportedSenders = $this->getSupportedSenders();
		if (empty($supportedSenders))
		{
			return [];
		}

		return $this->filterOutBookingsWithRecentPendingForClient(
			$this->doGetBookingIdsForSend(),
		);
	}

	/**
	 * Filters booking IDs that are still permanently eligible for retry.
	 * Checks business rules (booking not deleted, settings enabled, time windows, etc.)
	 * but does NOT check temporal send conditions (working hours, morning scenario).
	 * Bookings that fail this check are cancelled and will never be retried.
	 *
	 * @param int[] $bookingIds
	 * @return int[]
	 */
	public function filterEligibleForRetry(array $bookingIds): array
	{
		if (empty($bookingIds) || empty($this->getSupportedSenders()))
		{
			return [];
		}

		return $this->doFilterEligibleForRetry($bookingIds);
	}

	/**
	 * Filters booking IDs that can be sent right now (temporal conditions).
	 * Checks working hours, morning scenario, and other time-based rules.
	 * Bookings that fail this check are skipped until the next RetryAgent run.
	 *
	 * @param int[] $bookingIds
	 * @return int[]
	 */
	public function filterReadyToSendNow(array $bookingIds): array
	{
		if (empty($bookingIds))
		{
			return [];
		}

		return $this->filterOutBookingsWithRecentPendingForClient(
			$this->doFilterReadyToSendNow($bookingIds),
		);
	}

	/**
	 * Returns the notification type handled by this data source.
	 */
	abstract protected function getNotificationType(): NotificationType;

	/**
	 * Builds and executes the SQL query to find bookings needing notification.
	 * Includes all checks: business rules, delay conditions, and message existence.
	 *
	 * @return int[]
	 */
	abstract protected function doGetBookingIdsForSend(): array;

	/**
	 * Builds and executes the SQL query to check permanent retry eligibility.
	 * Includes business rules and success-message existence check,
	 * but excludes delay conditions (already satisfied at first send).
	 *
	 * @param int[] $bookingIds
	 * @return int[]
	 */
	abstract protected function doFilterEligibleForRetry(array $bookingIds): array;

	/**
	 * Applies temporal send conditions (working hours, morning scenario, etc.).
	 * Default implementation passes all IDs through (no temporal restrictions).
	 *
	 * @param int[] $bookingIds
	 * @return int[]
	 */
	protected function doFilterReadyToSendNow(array $bookingIds): array
	{
		return $bookingIds;
	}

	/**
	 * Returns SQL conditions ensuring the booking has a client
	 * and uses a sender that supports this notification type.
	 */
	protected function getClientAndSenderConditionsSql(): string
	{
		$clientExistsSql = $this->getClientExistsSql();
		$senderSupportedSql = $this->getSenderSupportedSql();

		return "
			AND {$clientExistsSql}
			AND {$senderSupportedSql}
		";
	}

	/**
	 * Returns SQL EXISTS subquery checking if any message
	 * of the given type was sent for the booking (regardless of status).
	 */
	protected function getAnyMessageExistsSql(NotificationType $notificationType): string
	{
		$type = $this->sqlHelper->forSql($notificationType->value);

		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_message
				WHERE
					BOOKING_ID = b.ID
					AND NOTIFICATION_TYPE = '{$type}'
				)
		";
	}

	/**
	 * Returns SQL EXISTS subquery checking if a successfully delivered message
	 * of the given type exists for the booking.
	 */
	protected function getSuccessMessageExistsSql(NotificationType $notificationType): string
	{
		$type = $this->sqlHelper->forSql($notificationType->value);
		$successStatus = $this->sqlHelper->forSql(BookingMessageStatus::Success->value);

		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_message
				WHERE
					BOOKING_ID = b.ID
					AND NOTIFICATION_TYPE = '{$type}'
					AND STATUS = '{$successStatus}'
				)
		";
	}

	/**
	 * Returns SQL condition filtering bookings with unknown visit status.
	 */
	protected function getVisitStatusUnknownSql(): string
	{
		$unknownVisitStatus = $this->sqlHelper->forSql(BookingVisitStatus::Unknown->value);

		return "
			b.VISIT_STATUS = '{$unknownVisitStatus}'
		";
	}

	protected function convertTimestampToDbExpr(int|string $timestamp): string
	{
		$timestamp = (string)$timestamp;

		if (Application::getConnection() instanceof PgsqlConnection)
		{
			return "TO_TIMESTAMP({$timestamp})";
		}

		return "FROM_UNIXTIME({$timestamp})";
	}

	protected function getBookingIdFilterSql(array $bookingIds): string
	{
		$ids = implode(',', array_map('intval', $bookingIds));

		return "b.ID IN ({$ids})";
	}

	/**
	 * Excludes bookings whose primary client already has an active Pending message
	 * sent within PENDING_LOCK_WINDOW_SECONDS. Prevents starting a second notification
	 * (e.g. AI call) while the previous one is still in progress for the same person,
	 * even if a new booking was created mid-call. The time window auto-lifts the lock
	 * if a Pending message gets stuck (no callback from the external system).
	 *
	 * @param int[] $bookingIds
	 * @return int[]
	 */
	private function filterOutBookingsWithRecentPendingForClient(array $bookingIds): array
	{
		if (empty($bookingIds))
		{
			return [];
		}

		$idsList = implode(',', array_map('intval', $bookingIds));
		$entityTypeBooking = $this->sqlHelper->forSql(EntityType::Booking->value);
		$pendingStatus = $this->sqlHelper->forSql(BookingMessageStatus::Pending->value);
		$thresholdDateTime = $this->convertTimestampToDbExpr(
			$this->currentTimestamp - self::PENDING_LOCK_WINDOW_SECONDS,
		);

		$sql = "
			SELECT bc.ENTITY_ID AS BOOKING_ID
			FROM b_booking_booking_client bc
			WHERE bc.ENTITY_ID IN ({$idsList})
				AND bc.ENTITY_TYPE = '{$entityTypeBooking}'
				AND bc.IS_PRIMARY = 'Y'
				AND EXISTS (
					SELECT 1
					FROM b_booking_booking_client bc_other
					JOIN b_booking_booking_message m ON
						m.BOOKING_ID = bc_other.ENTITY_ID
						AND m.STATUS = '{$pendingStatus}'
						AND m.SENT_AT > {$thresholdDateTime}
					WHERE bc_other.CLIENT_TYPE_ID = bc.CLIENT_TYPE_ID
						AND bc_other.CLIENT_ID = bc.CLIENT_ID
						AND bc_other.IS_PRIMARY = 'Y'
						AND bc_other.ENTITY_TYPE = '{$entityTypeBooking}'
				)
		";

		$blocked = array_map(
			static fn(array $row) => (int)$row['BOOKING_ID'],
			$this->connection->query($sql)->fetchAll(),
		);

		if (empty($blocked))
		{
			return $bookingIds;
		}

		return array_values(array_diff($bookingIds, $blocked));
	}

	private function getClientExistsSql(): string
	{
		$entityTypeBooking = $this->sqlHelper->forSql(EntityType::Booking->value);

		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_client
				WHERE
					ENTITY_ID = b.ID
					AND ENTITY_TYPE = '{$entityTypeBooking}'
			)
		";
	}

	/**
	 * Returns SQL condition filtering resources whose sender code
	 * is among the senders supporting this notification type.
	 */
	private function getSenderSupportedSql(): string
	{
		$senderValues = implode(
			', ',
			array_map(
				function ($senderCode) {
					return "'{$this->sqlHelper->forSql($senderCode)}'";
				},
				$this->getSupportedSenders(),
			),
		);

		return "
			rns.SENDER_CODE IN ({$senderValues})
		";
	}

	/**
	 * Returns sender codes that support this notification type.
	 *
	 * @return string[]
	 */
	private function getSupportedSenders(): array
	{
		return array_map(
			static fn(BaseMessageSender $sender) => $sender->getCode(),
			$this->senderNotification->getSendersByNotificationType($this->getNotificationType()),
		);
	}
}
