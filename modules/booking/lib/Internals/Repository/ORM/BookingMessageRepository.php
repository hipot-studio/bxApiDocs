<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;
use Bitrix\Booking\Internals\Model\BookingMessageTable;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class BookingMessageRepository implements BookingMessageRepositoryInterface
{
	public function save(BookingMessage $bookingMessage): int
	{
		$result = BookingMessageTable::add([
			'BOOKING_ID' => $bookingMessage->getBookingId(),
			'NOTIFICATION_TYPE' => $bookingMessage->getNotificationType()->value,
			'SENDER_CODE' => $bookingMessage->getSenderCode(),
			'EXTERNAL_MESSAGE_ID' => $bookingMessage->getExternalMessageId(),
		]);

		if (!$result->isSuccess())
		{
			throw new Exception(implode(', ', $result->getErrorMessages()));
		}

		$bookingMessage->setId($result->getId());

		return $bookingMessage->getId();
	}

	public function getLastByBookingId(int $bookingId): BookingMessage|null
	{
		$row = BookingMessageTable::query()
			->setSelect($this->getDefaultSelect())
			->where('BOOKING_ID', '=', $bookingId)
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return $this->createEntityFromOrmRow($row);
	}

	public function getByExternalId(string $senderCode, string $externalId): BookingMessage|null
	{
		$bookingMessage = BookingMessageTable::query()
			->setSelect($this->getDefaultSelect())
			->setLimit(1)
			->where('SENDER_CODE', '=', $senderCode)
			->where('EXTERNAL_MESSAGE_ID', '=', $externalId)
			->exec()
			->fetch();
		;
		if (!$bookingMessage)
		{
			return null;
		}

		return $this->createEntityFromOrmRow($bookingMessage);
	}

	public function getByBookingIds(array $bookingIds): BookingMessageCollection
	{
		$result = new BookingMessageCollection();

		$bookingMessages = BookingMessageTable::query()
			->setSelect($this->getDefaultSelect())
			->whereIn('BOOKING_ID', $bookingIds)
			->exec()
			->fetchAll();
		;

		foreach ($bookingMessages as $bookingMessage)
		{
			$result->add(
				$this->createEntityFromOrmRow($bookingMessage)
			);
		}

		return $result;
	}

	private function createEntityFromOrmRow(array $row): BookingMessage
	{
		$entity = new BookingMessage();

		if (isset($row['ID']))
		{
			$entity->setId((int)$row['ID']);
		}

		if (isset($row['BOOKING_ID']))
		{
			$entity->setBookingId((int)$row['BOOKING_ID']);
		}

		if (isset($row['NOTIFICATION_TYPE']))
		{
			$entity->setNotificationType(NotificationType::tryFrom($row['NOTIFICATION_TYPE']));
		}

		if (isset($row['SENDER_CODE']))
		{
			$entity->setSenderCode($row['SENDER_CODE']);
		}

		if (isset($row['EXTERNAL_MESSAGE_ID']))
		{
			$entity->setExternalMessageId((string)$row['EXTERNAL_MESSAGE_ID']);
		}

		return $entity;
	}

	private function getDefaultSelect(): array
	{
		return [
			'ID',
			'BOOKING_ID',
			'NOTIFICATION_TYPE',
			'SENDER_CODE',
			'EXTERNAL_MESSAGE_ID',
		];
	}
}
