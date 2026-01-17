<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Item;

use Bitrix\Booking\Entity\Booking\BookingVisitStatus;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Item;
use Bitrix\Booking\Entity;
use DateTimeInterface;

class Booking extends Item
{
	private string $id;
	private BookingStatus $status;
	private array $serviceIds;
	private string $resourceId;
	private string $datetime;
	private string|null $comment;

	public function __construct(
		string $id,
		BookingStatus $status,
		array $serviceIds,
		string $resourceId,
		string $datetime,
		string|null $comment = null,
	)
	{
		$this->id = $id;
		$this->status = $status;
		$this->serviceIds = array_map('strval', $serviceIds);
		$this->resourceId = $resourceId;
		$this->datetime = $datetime;
		$this->comment = $comment;
	}

	protected function __toArray(): array
	{
		return [
			'id' => $this->id,
			'status' => $this->status->value,
			'serviceIds' => $this->serviceIds,
			'resourceId' => $this->resourceId,
			'datetime' => $this->datetime,
			'comment' => $this->comment,
		];
	}

	public static function createFromBooking(Entity\Booking\Booking $booking): self
	{
		$primaryResourceId = $booking->getPrimaryResource()?->getId();
		$serviceIds = $booking->getSkuCollection()->getEntityIds();

		return new self(
			id: (string)$booking->getId(),
			status: self::getBookingStatus($booking),
			serviceIds: array_map('strval', $serviceIds),
			resourceId: isset($primaryResourceId) ? (string)$primaryResourceId : null,
			datetime: $booking->getDatePeriod()->getDateFrom()->format(DateTimeInterface::ATOM),
			comment: $booking->getClientNote(),
		);
	}

	private static function getBookingStatus(Entity\Booking\Booking $booking): BookingStatus
	{
		if ($booking->isDeleted())
		{
			return BookingStatus::Cancelled;
		}

		if ($booking->getVisitStatus() === BookingVisitStatus::Visited)
		{
			return BookingStatus::Visited;
		}

		if ($booking->getVisitStatus() === BookingVisitStatus::NotVisited)
		{
			return BookingStatus::NotVisited;
		}

		if ($booking->isConfirmed())
		{
			return BookingStatus::Confirmed;
		}

		return BookingStatus::Created;
	}
}
