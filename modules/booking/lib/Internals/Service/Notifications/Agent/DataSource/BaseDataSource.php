<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Agent\DataSource;

use Bitrix\Booking\Entity\Booking\BookingVisitStatus;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BaseMessageSender;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSenderNotification;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\PgsqlConnection;
use DateTimeImmutable;
use DateTimeZone;

abstract class BaseDataSource
{
	protected Connection $connection;
	private MessageSenderNotification $senderNotification;

	public function __construct()
	{
		$this->connection = Application::getConnection();
		$this->senderNotification = Container::getMessageSenderNotification();
	}

	public function getBookingIds(): array
	{
		$supportedSenders = $this->getSupportedSenders();
		if (empty($supportedSenders))
		{
			return [];
		}

		return $this->doGetBookingIds();
	}

	abstract protected function getNotificationType(): NotificationType;

	abstract protected function doGetBookingIds(): array;

	protected function getWhereSql(): string
	{
		return "
			AND " . $this->getClientExistsSql() . "
			AND " . $this->getSenderSupportedSql() . "
		";
	}

	protected function getMessageExistsSql(NotificationType $notificationType): string
	{
		return "
			EXISTS (
				SELECT 1
				FROM b_booking_booking_message
				WHERE
					BOOKING_ID = b.ID
					AND NOTIFICATION_TYPE = '" . $this->connection->getSqlHelper()->forSql($notificationType->value) . "'
				)
		";
	}

	protected function getVisitStatusUnknownSql(): string
	{
		$unknownVisitStatus = $this->connection->getSqlHelper()->forSql(BookingVisitStatus::Unknown->value);

		return "
			b.VISIT_STATUS = '$unknownVisitStatus'
		";
	}

	protected function isWorkingHours(int $timestamp, string $timezone): bool
	{
		$currentDateTime = (new DateTimeImmutable('@' . $timestamp))
			->setTimezone(new DateTimeZone($timezone))
		;
		$currentHour = (int)$currentDateTime->format('H');

		return (
			$currentHour >= Time::DAYTIME_START_HOUR
			&& $currentHour < Time::DAYTIME_END_HOUR
		);
	}

	protected function isSameDay(int $timestamp1, int $timestamp2, string $timezone): bool
	{
		$currentDateTime = (new DateTimeImmutable('@' . $timestamp1))
			->setTimezone(new DateTimeZone($timezone))
		;

		$dateTime = (new DateTimeImmutable('@' . $timestamp2))
			->setTimezone(new DateTimeZone($timezone))
		;

		return $currentDateTime->format('Ymd') === $dateTime->format('Ymd');
	}

	protected function makeDateTimeFromTimestamp(int|string $timestamp): string
	{
		$timestamp = (string)$timestamp;

		if (Application::getConnection() instanceof PgsqlConnection)
		{
			return 'TO_TIMESTAMP(' . $timestamp . ')';
		}

		return 'FROM_UNIXTIME(' . $timestamp . ')';
	}

	private function getClientExistsSql(): string
	{
		$entityTypeBooking = $this->connection->getSqlHelper()->forSql(EntityType::Booking->value);

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

	private function getSenderSupportedSql(): string
	{
		$senderValues = implode(
			', ',
			array_map(
				fn ($senderCode) => "'" . $this->connection->getSqlHelper()->forSql($senderCode) . "'",
				$this->getSupportedSenders()
			)
		);

		return "
			rns.SENDER_CODE IN ($senderValues)
		";
	}

	private function getSupportedSenders(): array
	{
		return array_map(
			static fn(BaseMessageSender $sender) => $sender->getCode(),
			$this->senderNotification->getSendersByNotificationType($this->getNotificationType())
		);
	}
}
