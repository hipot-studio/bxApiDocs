<?php

declare(strict_types=1);

namespace Bitrix\Booking\Command\Booking;

use Bitrix\Booking\Command\Booking\Trait\BookingChangesTrait;
use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Exception\Booking\CreateBookingException;
use Bitrix\Booking\Internals\Exception\Booking\UpdateBookingException;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Repository\ORM\BookingResourceRepository;
use Bitrix\Booking\Internals\Service\BookingService;
use Bitrix\Booking\Internals\Service\BookingSkuService;
use Bitrix\Booking\Internals\Service\ClientService;
use Bitrix\Booking\Internals\Service\Journal\JournalEvent;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\Journal\JournalType;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingExternalDataRepository;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\Overbooking\OverbookingService;
use Bitrix\Booking\Internals\Service\ResourceService;
use Bitrix\Booking\Provider\BookingProvider;
use Bitrix\Booking\Service\BookingFeature;

class UpdateBookingCommandHandler
{
	use BookingChangesTrait;

	private BookingRepositoryInterface $bookingRepository;
	private BookingExternalDataRepository $bookingExternalDataRepository;
	private BookingResourceRepository $bookingResourceRepository;
	private TransactionHandlerInterface $transactionHandler;
	private JournalServiceInterface $journalService;
	private BookingService $bookingService;
	private BookingSkuService $skuService;
	private OverbookingService $overbookingService;
	private BookingProvider $bookingProvider;
	private ClientService $clientService;
	private ResourceService $resourceService;

	public function __construct()
	{
		$this->bookingRepository = Container::getBookingRepository();
		$this->bookingExternalDataRepository = Container::getBookingExternalDataRepository();
		$this->bookingResourceRepository = Container::getBookingResourceRepository();
		$this->transactionHandler = Container::getTransactionHandler();
		$this->journalService = Container::getJournalService();
		$this->bookingService = Container::getBookingService();
		$this->skuService = Container::getBookingSkuService();
		$this->overbookingService = Container::getOverbookingService();
		$this->clientService = Container::getClientService();
		$this->resourceService = Container::getResourceService();
		$this->bookingProvider = new BookingProvider();
	}

	public function __invoke(UpdateBookingCommand $command): Entity\Booking\Booking
	{
		$this->checkFeatures($command);

		$currentBooking = $this->bookingRepository->getById($command->booking->getId());

		if (!$currentBooking)
		{
			throw new UpdateBookingException('Booking not found');
		}

		$commandResources = clone $command->booking->getResourceCollection();
		$loadedResources = $this->resourceService->loadResourceCollection($commandResources);

		$notFoundIds = array_diff($commandResources->getEntityIds(), $loadedResources->getEntityIds());
		if (!empty($notFoundIds))
		{
			throw new UpdateBookingException(
				'Some resources were not found: ' . implode(', ', $notFoundIds)
			);
		}

		$command->booking->setResourceCollection($loadedResources);

		try
		{
			$this->bookingService->checkBookingBeforeUpdating($currentBooking, $command->booking);
		}
		catch (\Throwable $exception)
		{
			throw new UpdateBookingException($exception->getMessage());
		}

		$intersectionResult = $this->bookingService->checkIntersection(
			booking: $command->booking,
			allowOverbooking: $command->allowOverbooking,
		);
		if (!$intersectionResult->isSuccess())
		{
			throw new UpdateBookingException(
				'Some resources are unavailable for the requested time range: '
				. implode(',', $intersectionResult->getBookingCollection()->getEntityIds())
			);
		}

		$booking = $this->transactionHandler->handle(
			fn: function() use ($command, $currentBooking, $intersectionResult) {
				$this->handleResources($command->booking, $currentBooking);
				$this->handleClients($command, $currentBooking);
				$this->handleExternalData($command, $currentBooking);
				$this->skuService->handleSkuRelations(
					$command->booking,
					$currentBooking->getSkuCollection(),
				);

				$bookingId = $this->bookingRepository->save($command->booking);
				$booking = $this->bookingRepository->getById($bookingId);
				if (!$booking)
				{
					throw new UpdateBookingException();
				}

				// load booking external clients info
				Container::getProviderManager()::getCurrentProvider()
					?->getClientProvider()
					?->loadClientDataForCollection($booking->getClientCollection());

				Container::getDealForBookingService()->onBookingUpdated($currentBooking, $booking);

				$this->bookingProvider->withExternalData(new Entity\Booking\BookingCollection($booking));

				Container::getProviderManager()::getCurrentProvider()
					?->getDataProvider()
					?->updateBindings($booking, $currentBooking);

				$this->journalService->append(
					new JournalEvent(
						entityId: $command->booking->getId(),
						type: JournalType::BookingUpdated,
						data: [
							...$command->toArray(),
							'booking' => $booking->toArray(),
							'currentUserId' => $command->updatedBy,
							'prevBooking' => $currentBooking->toArray(),
							'isOverbooking' => $intersectionResult->hasIntersections(),
						],
					),
				);

				$this->processBookingChanges(
					$currentBooking,
					$booking,
					$intersectionResult,
					$command->updatedBy,
				);

				if (!$currentBooking->isConfirmed() && $booking->isConfirmed())
				{
					$this->journalService->append(
						new JournalEvent(
							entityId: $command->booking->getId(),
							type: JournalType::BookingConfirmed,
							data: [
								'booking' => $booking->toArray(),
							],
						)
					);
				}

				// TODO: if BookingRepository::getById refactored and stop return counters without condition
				// refactor this, check usages for proper counters loading
				if ($command->updatedBy > 0)
				{
					// update counters cause it may be changed during booking update process
					$this->bookingProvider->withCounters(
						new Entity\Booking\BookingCollection($booking),
						$command->updatedBy
					);
				}

				return $booking;
			},
			errType: UpdateBookingException::class,
		);

		try
		{
			Container::getEventForBookingService()->onBookingUpdated($currentBooking, $booking);
		}
		catch (\Throwable $e)
		{
			// TODO: add error handling
		}

		return $booking;
	}

	private function handleResources(Entity\Booking\Booking $newBooking, Entity\Booking\Booking $currentBooking): void
	{
		$newResources = $newBooking->getResourceCollection();
		$existingResources = $currentBooking->getResourceCollection();

		if ($newResources->isEqual($existingResources))
		{
			return;
		}

		if (!$existingResources->isEmpty())
		{
			$this->bookingResourceRepository->unLink($currentBooking, $existingResources);
		}

		if (!$newResources->isEmpty())
		{
			$this->bookingResourceRepository->link($currentBooking, $newResources);
		}

		$newBooking->setResourceCollection($newResources);
	}

	private function handleClients(UpdateBookingCommand $command, Entity\Booking\Booking $booking): void
	{
		$isUpdated = $this->clientService->handleClientRelationsUpdate(
			newClients: $command->booking->getClientCollection(),
			existingClients: $booking->getClientCollection(),
			newExternalData: $command->booking->getExternalDataCollection(),
			entity: $booking,
			entityType: EntityType::Booking,
		);

		if ($isUpdated)
		{
			$this->journalService->append(
				new JournalEvent(
					entityId: $command->booking->getId(),
					type: JournalType::BookingClientsUpdated,
					data: [],
				),
			);
		}
	}

	private function handleExternalData(UpdateBookingCommand $command, Entity\Booking\Booking $booking): void
	{
		$newItems = $command->booking->getExternalDataCollection();
		$existingItems = $booking->getExternalDataCollection();

		if ($newItems->isEqual($existingItems))
		{
			return;
		}

		if (!$existingItems->isEmpty())
		{
			$unlink = $existingItems->diff($newItems);
			$this->bookingExternalDataRepository->unLink($booking->getId(), EntityType::Booking, $unlink);
		}

		if (!$newItems->isEmpty())
		{
			$link = $newItems->diff($existingItems);
			$this->bookingExternalDataRepository->link($booking->getId(), EntityType::Booking, $link);
		}
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

	private function checkFeatures(UpdateBookingCommand $command): void
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
