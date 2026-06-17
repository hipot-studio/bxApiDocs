<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository\ORM;

use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\Notifications\BookingMessageStatus;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;
use Bitrix\Booking\Internals\Model\BookingMessageTable;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\BookingMessageMapper;
use Bitrix\Main\Type\DateTime;

class BookingMessageRepository implements BookingMessageRepositoryInterface
{
	public function __construct(
		private readonly BookingMessageMapper $mapper,
	)
	{
	}

	public function save(BookingMessage $bookingMessage): void
	{
		$fields = [
			'BOOKING_ID' => $bookingMessage->getBookingId(),
			'NOTIFICATION_TYPE' => $bookingMessage->getNotificationType()->value,
			'SENDER_CODE' => $bookingMessage->getSenderCode(),
			'EXTERNAL_MESSAGE_ID' => $bookingMessage->getExternalMessageId(),
			'STATUS' => $bookingMessage->getStatus()?->value ?? BookingMessageStatus::Success->value,
			'RETRY_COUNT' => $bookingMessage->getRetryCount(),
			'NEXT_RETRY_AT' => $bookingMessage->getNextRetryAt() !== null
				? DateTime::createFromTimestamp($bookingMessage->getNextRetryAt())
				: null,
			'SENT_AT' => $bookingMessage->getSentAt() !== null
				? DateTime::createFromTimestamp($bookingMessage->getSentAt())
				: null,
		];

		if ($bookingMessage->getId())
		{
			$result = BookingMessageTable::update($bookingMessage->getId(), $fields);
		}
		else
		{
			$result = BookingMessageTable::add($fields);
		}

		if (!$result->isSuccess())
		{
			throw new Exception(implode(', ', $result->getErrorMessages()));
		}

		if (!$bookingMessage->getId())
		{
			$bookingMessage->setId($result->getId());
		}
	}

	public function delete(int $id): void
	{
		$result = BookingMessageTable::delete($id);

		if (!$result->isSuccess())
		{
			throw new Exception(implode(', ', $result->getErrorMessages()));
		}
	}

	public function getFailedForRetry(int $maxRetries, int $limit): BookingMessageCollection
	{
		$result = new BookingMessageCollection();

		$queryResult = BookingMessageTable::query()
			->setSelect(['*'])
			->where('STATUS', '=', BookingMessageStatus::Failed->value)
			->whereNotNull('NEXT_RETRY_AT')
			->where('NEXT_RETRY_AT', '<=', new DateTime())
			->where('RETRY_COUNT', '<', $maxRetries)
			->setOrder(['NEXT_RETRY_AT' => 'ASC'])
			->setLimit($limit)
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$result->add($this->mapper->convertFromRow($row));
		}

		return $result;
	}

	public function getById(int $id): BookingMessage|null
	{
		$row = BookingMessageTable::getByPrimary($id)->fetch();
		if (!$row)
		{
			return null;
		}

		return $this->mapper->convertFromRow($row);
	}

	public function getLastByBookingId(int $bookingId): BookingMessage|null
	{
		$row = BookingMessageTable::query()
			->setSelect(['*'])
			->where('BOOKING_ID', '=', $bookingId)
			->setOrder(['SENT_AT' => 'DESC'])
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return $this->mapper->convertFromRow($row);
	}

	public function getByExternalId(string $senderCode, string $externalId): BookingMessage|null
	{
		$row = BookingMessageTable::query()
			->setSelect(['*'])
			->setLimit(1)
			->where('SENDER_CODE', '=', $senderCode)
			->where('EXTERNAL_MESSAGE_ID', '=', $externalId)
			->exec()
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		return $this->mapper->convertFromRow($row);
	}

	public function getByBookingIds(array $bookingIds): BookingMessageCollection
	{
		$result = new BookingMessageCollection();

		$queryResult = BookingMessageTable::query()
			->setSelect(['*'])
			->whereIn('BOOKING_ID', $bookingIds)
			->exec()
		;

		while ($row = $queryResult->fetch())
		{
			$result->add(
				$this->mapper->convertFromRow($row)
			);
		}

		return $result;
	}
}
