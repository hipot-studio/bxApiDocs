<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Exception\Booking\CreateBookingException;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Service\Overbooking\IntersectionResult;
use Bitrix\Booking\Internals\Service\Overbooking\OverlapPolicy;

class BookingService
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly ResourceService $resourceService,
		private readonly ClientService $clientService,
		private readonly ExternalDataService $externalDataService,
		private readonly OverlapPolicy $overbookingOverlapPolicy
	)
	{
	}

	public function create(Entity\Booking\Booking $newBooking, int $userId): Entity\Booking\Booking
	{
		$newBooking->setCreatedBy($userId);
		$bookingId = $this->bookingRepository->save($newBooking);
		$bookingEntity = $this->bookingRepository->getById($bookingId);

		if (!$bookingEntity)
		{
			throw new CreateBookingException();
		}

		$this->resourceService->handleResourceRelations($bookingEntity, $newBooking->getResourceCollection());

		$this->clientService->handleClientRelations(
			$newBooking->getClientCollection(),
			$bookingEntity,
			EntityType::Booking,
		);

		$this->externalDataService->handleExternalDataRelations(
			$newBooking->getExternalDataCollection(),
			$newBooking->getClientCollection(),
			$bookingEntity,
			EntityType::Booking,
		);

		return $bookingEntity;
	}

	public function buildFromWaitListItem(
		Entity\WaitListItem\WaitListItem $waitListItem,
		array $resources,
		array $datePeriod,
		int $createdBy,
		string|null $name = null,
	): Entity\Booking\Booking
	{
		$booking = new Entity\Booking\Booking();
		$booking
			->setCreatedBy($createdBy)
			->setName($name)
			->setClientCollection(
				new Entity\Client\ClientCollection(...$waitListItem->getClientCollection()->getCollectionItems())
			)
			->setExternalDataCollection(
				new Entity\ExternalData\ExternalDataCollection(
					...$waitListItem->getExternalDataCollection()->getCollectionItems()
				)
			)
			->setResourceCollection(Entity\Resource\ResourceCollection::mapFromArray($resources))
			->setDatePeriodFromArray($datePeriod)
			->setNote($waitListItem->getNote())
		;

		return $booking;
	}

	public function checkBookingBeforeCreating(
		Entity\Booking\Booking $booking,
	): void
	{
		if ($booking->getResourceCollection()->isEmpty())
		{
			throw new Exception('Empty resource collection');
		}

		if ($booking->getDatePeriod() === null)
		{
			throw new Exception('Date period is not specified');
		}

		if ($booking->isAutoConfirmed())
		{
			$booking->setConfirmed(true);
		}
	}

	public function checkIntersection(Entity\Booking\Booking $booking, bool $allowOverbooking): IntersectionResult
	{
		if ($allowOverbooking)
		{
			$intersectionResult = $this->overbookingOverlapPolicy->getIntersectionsList(
				$booking,
				$this->bookingRepository->getIntersectionsList($booking)
			);
		}
		else
		{
			$intersectingBookings = $this->bookingRepository->getIntersectionsList($booking);
			$intersectionResult = (new IntersectionResult($intersectingBookings))
				->setIsSuccess($intersectingBookings->isEmpty());
		}

		if (!$intersectionResult->isSuccess())
		{
			throw new Exception(
				'Some resources are unavailable for the requested time range: '
				. implode(',', $intersectionResult->getBookingCollection()->getEntityIds()),
				Exception::CODE_BOOKING_INTERSECTION
			);
		}

		return $intersectionResult;
	}
}
