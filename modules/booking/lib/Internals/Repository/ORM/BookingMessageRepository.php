<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;
use Bitrix\Booking\Internals\Model\BookingMessageTable;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\BookingMessageMapper;

class BookingMessageRepository implements BookingMessageRepositoryInterface
{
	public function __construct(
		private readonly BookingMessageMapper $mapper,
	)
	{
	}

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

	public function getById(int $id): BookingMessage|null
	{
		$ormBookingMessage = BookingMessageTable::getByPrimary($id)->fetchObject();
		if (!$ormBookingMessage)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormBookingMessage);
	}

	public function getLastByBookingId(int $bookingId): BookingMessage|null
	{
		$ormBookingMessage = BookingMessageTable::query()
			->setSelect(['*'])
			->where('BOOKING_ID', '=', $bookingId)
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;

		if (!$ormBookingMessage)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormBookingMessage);
	}

	public function getByExternalId(string $senderCode, string $externalId): BookingMessage|null
	{
		$ormBookingMessage = BookingMessageTable::query()
			->setSelect(['*'])
			->setLimit(1)
			->where('SENDER_CODE', '=', $senderCode)
			->where('EXTERNAL_MESSAGE_ID', '=', $externalId)
			->fetchObject()
		;

		if (!$ormBookingMessage)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormBookingMessage);
	}

	public function getByBookingIds(array $bookingIds): BookingMessageCollection
	{
		$result = new BookingMessageCollection();

		$ormBookingMessages = BookingMessageTable::query()
			->setSelect(['*'])
			->whereIn('BOOKING_ID', $bookingIds)
			->fetchCollection()
		;

		foreach ($ormBookingMessages as $ormBookingMessage)
		{
			$result->add(
				$this->mapper->convertFromOrm($ormBookingMessage)
			);
		}

		return $result;
	}
}
