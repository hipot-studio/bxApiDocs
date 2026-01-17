<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Command\Booking\Trait\BookingChangesTrait;
use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Exception\Booking\CreateBookingException;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Booking\UpdateBookingException;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\BookingService;
use Bitrix\Booking\Internals\Service\EventForBookingService;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalType;
use Bitrix\Booking\Internals\Service\Overbooking\OverbookingService;
use Bitrix\Booking\Internals\Service\ResourceService;
use Bitrix\Booking\Service\BookingFeature;

class AddBookingCommandHandler
{
	use BookingChangesTrait;

	private JournalServiceInterface $journalService;
	private TransactionHandlerInterface $transactionHandler;
	private ResourceService $resourceService;
	private BookingService $bookingService;
	private OverbookingService $overbookingService;
	private BookingRepositoryInterface $bookingRepository;
	private EventForBookingService $eventForBookingService;

	public function __construct()
	{
		$this->journalService = Container::getJournalService();
		$this->transactionHandler = Container::getTransactionHandler();
		$this->resourceService = Container::getResourceService();
		$this->bookingService = Container::getBookingService();
		$this->overbookingService = Container::getOverbookingService();
		$this->bookingRepository = Container::getBookingRepository();
		$this->eventForBookingService = Container::getEventForBookingService();
	}

	public function __invoke(AddBookingCommand $command): Entity\Booking\Booking
	{
		$this->checkFeatures($command);

		$this->transactionHandler->handle(
			fn: function () use ($command) {
				$commandResources = clone $command->booking->getResourceCollection();
				$loadedResources = $this->resourceService->loadResourceCollection($commandResources)->getNotDeleted();

				$notFoundIds = array_diff($commandResources->getEntityIds(), $loadedResources->getEntityIds());
				if (!empty($notFoundIds))
				{
					throw new UpdateBookingException(
						'Some resources were not found: ' . implode(', ', $notFoundIds)
					);
				}

				$command->booking->setResourceCollection($loadedResources);
			},
			errType: CreateBookingException::class,
		);

		try
		{
			$this->bookingService->checkBookingBeforeCreating($command->booking);
		}
		catch (\Throwable $exception)
		{
			throw new CreateBookingException($exception->getMessage());
		}

		$intersectionResult = $this->bookingService->checkIntersection(
			booking: $command->booking,
			allowOverbooking: $command->allowOverbooking,
		);
		if (!$intersectionResult->isSuccess())
		{
			throw new CreateBookingException(
				'Some resources are unavailable for the requested time range: '
				. implode(',', $intersectionResult->getBookingCollection()->getEntityIds())
			);
		}

		$booking = $this->transactionHandler->handle(
			fn: function () use ($command, $intersectionResult) {
				$booking = $this->bookingService->create($command->booking, $command->createdBy);

				$this->journalService->append(
					new JournalEvent(
						entityId: $booking->getId(),
						type: JournalType::BookingAdded,
						data: array_merge(
							$command->toArray(),
							[
								'booking' => $booking->toArray(),
								'currentUserId' => $command->createdBy,
								'isOverbooking' => $intersectionResult->hasIntersections(),
							],
						),
					),
				);

				if ($intersectionResult->hasIntersections())
				{
					$events = $this->prepareOverbookingUpdateEvents(
						intersectionBookings: $intersectionResult->getBookingCollection(),
						updatedBy: $command->createdBy,
						isOverbooking: true,
					);
					array_map(fn(JournalEvent $event) => $this->journalService->append($event), $events);
				}

				return $booking;
			},
			errType: CreateBookingException::class,
		);

		try
		{
			$this->eventForBookingService->onBookingCreated($booking);
		}
		catch (\Throwable $e)
		{
			// TODO: add error handling
		}

		return $booking;
	}

	protected function getOverbookingService(): OverbookingService
	{
		return $this->overbookingService;
	}

	protected function getBookingRepository(): BookingRepositoryInterface
	{
		return $this->bookingRepository;
	}

	protected function getJournalService(): JournalServiceInterface
	{
		return $this->journalService;
	}

	private function checkFeatures(AddBookingCommand $command): void
	{
		if (!BookingFeature::isFeatureEnabled(BookingFeature::FEATURE_ID_BOOKING))
		{
			throw new Exception('Feature is not available');
		}

		if (
			!BookingFeature::isFeatureEnabled(BookingFeature::FEATURE_ID_MULTI_RESOURCE_BOOKING)
			&& $command->booking->getResourceCollection()->count() > 1
		)
		{
			throw new Exception('Multi-resource booking feature is not available');
		}
	}
}
