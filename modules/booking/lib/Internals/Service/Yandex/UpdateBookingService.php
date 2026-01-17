<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Command\Booking\BookingResult;
use Bitrix\Booking\Command\Booking\UpdateBookingCommand;
use Bitrix\Booking\Entity\Booking\BookingSource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Exception\Yandex\BookingNotFoundException;
use Bitrix\Booking\Internals\Exception\Yandex\BookingUpdateForbiddenException;
use Bitrix\Booking\Internals\Exception\Yandex\ResourceNotFoundException;
use Bitrix\Booking\Internals\Exception\Yandex\ServiceNotFoundException;
use Bitrix\Booking\Internals\Exception\Yandex\SlotUnavailableException;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;
use DateTimeImmutable;
use DateTimeInterface;
use Bitrix\Booking\Internals\Service\Yandex;

class UpdateBookingService
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly FindResourceService $findResourceService,
	)
	{
	}

	public function update(
		string $bookingId,
		string $datetime,
		string|null $comment = null,
	): Yandex\Dto\Api\Item\Booking
	{
		$dateFrom = DateTimeImmutable::createFromFormat(
			DateTimeInterface::ATOM,
			$datetime,
		);
		if ($dateFrom->getTimestamp() < time())
		{
			throw new BookingUpdateForbiddenException();
		}

		$id = (int)$bookingId;

		$booking = $this->bookingRepository->getById(
			id: $id,
			withCounters: false,
			withClientsData: false,
			withExternalData: false,
			withSkus: false,
		);

		if (
			!$booking
			|| $booking->getSource() !== BookingSource::Yandex
		)
		{
			throw new BookingNotFoundException();
		}

		if ($booking->getResourceCollection()->count() > 1)
		{
			throw new BookingUpdateForbiddenException();
		}

		$primaryResource = $booking->getPrimaryResource();
		if (!$primaryResource)
		{
			throw new ResourceNotFoundException();
		}

		$skuIds = $booking->getSkuCollection()->getEntityIds();
		if (empty($skuIds))
		{
			throw new ServiceNotFoundException();
		}

		$resourceFilter = [
			'WITH_SKUS' => true,
			'HAS_SKUS' => $skuIds,
		];

		try
		{
			/**
			 * Same resource
			 */
			$findResourceResult = $this->findResourceService->findResource(
				array_merge(
					$resourceFilter,
					[
						'ID' => $primaryResource->getId(),
					],
				),
				$dateFrom,
				$booking,
			);
		}
		catch (ResourceNotFoundException|SlotUnavailableException)
		{
			/**
			 * Other resources
			 */
			$findResourceResult = $this->findResourceService->findResource(
				$resourceFilter,
				$dateFrom,
				$booking,
			);
			$booking->setResourceCollection(new ResourceCollection($findResourceResult->resource));
		}

		/** @var Result|BookingResult $updateResult */
		$updateResult = (new UpdateBookingCommand(
			updatedBy: (int)CurrentUser::get()->getId(),
			booking: $booking
				->setDatePeriod($findResourceResult->datePeriod)
				->setClientNote($comment)
			,
		))->run();

		if (!$updateResult->isSuccess())
		{
			throw new BookingUpdateForbiddenException();
		}

		return Yandex\Dto\Api\Item\Booking::createFromBooking($updateResult->getBooking());
	}
}
