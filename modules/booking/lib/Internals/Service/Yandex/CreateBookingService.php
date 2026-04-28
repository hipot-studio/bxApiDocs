<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Command\Booking\AddBookingCommand;
use Bitrix\Booking\Command\Booking\BookingResult;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Request\Booking\CreateBookingAppointment;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Request\Booking\CreateBookingRequest;
use Bitrix\Booking\Controller\V1\BookingService\Yandex\Request\Booking\CreateBookingUser;
use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Entity\Booking\BookingSource;
use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\Client\ClientCollection;
use Bitrix\Booking\Entity\Client\ClientType;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Exception\Yandex\BookingCreateForbiddenException;
use Bitrix\Booking\Internals\Exception\Yandex\ResourceNotFoundException;
use Bitrix\Booking\Internals\Exception\Yandex\ServiceNotFoundException;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Catalog\SkuProviderConfig;
use Bitrix\Booking\Internals\Integration\Crm\ContactSearcher\ContactDto;
use Bitrix\Booking\Internals\Integration\Crm\ContactSearcher\ContactSearcherService;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Result;
use DateTimeImmutable;
use DateTimeInterface;
use Bitrix\Booking\Internals\Service\Yandex;

class CreateBookingService
{
	public function __construct(
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly ServiceSkuProvider $serviceSkuProvider,
		private readonly ContactSearcherService $contactSearcherService,
		private readonly FindResourceService $findResourceService,
	)
	{
	}

	public function create(CreateBookingRequest $createBookingRequest): Yandex\Dto\Api\Item\Booking
	{
		$appointment = $createBookingRequest->getAppointment();

		$dateFrom = DateTimeImmutable::createFromFormat(
			DateTimeInterface::ATOM,
			$appointment->getDatetime(),
		);
		if ($dateFrom->getTimestamp() < time())
		{
			throw new BookingCreateForbiddenException();
		}

		$resourceId = $appointment->getResourceId() !== null ? (int)$appointment->getResourceId() : null;
		if ($resourceId !== null)
		{
			$resource = $this->resourceRepository->getById($resourceId);
			if ($resource === null)
			{
				throw new ResourceNotFoundException();
			}
		}

		$skuCollection = $this->createSkuCollection($appointment);

		$resourceFilter = [
			'WITH_SKUS_YANDEX' => true,
			'HAS_SKUS_YANDEX' => $skuCollection->getEntityIds(),
		];
		if ($resourceId !== null)
		{
			$resourceFilter['ID'] = $resourceId;
		}

		$findResourceResult = $this->findResourceService->findResource($resourceFilter, $dateFrom);

		$contactId = $this->findOrCreateContact($createBookingRequest->getUser());
		if (!$contactId)
		{
			throw new BookingCreateForbiddenException();
		}

		/** @var Result|BookingResult $addResult */
		$addResult = (new AddBookingCommand(
			createdBy: (int)CurrentUser::get()->getId(),
			booking: (new Booking())
				->setSource(BookingSource::Yandex)
				->setClientNote($createBookingRequest->getComment())
				->setDatePeriod($findResourceResult->datePeriod)
				->setResourceCollection(new ResourceCollection($findResourceResult->resource))
				->setSkuCollection($skuCollection)
				->setClientCollection(
					new ClientCollection(
						(new Client())
							->setId($contactId)
							->setType(
								(new ClientType())
									->setModuleId('crm')
									->setCode(\CCrmOwnerType::ContactName)
							)
					)
				)
			,
		))->run();

		if (!$addResult->isSuccess())
		{
			throw new BookingCreateForbiddenException();
		}

		return Yandex\Dto\Api\Item\Booking::createFromBooking($addResult->getBooking());
	}

	private function createSkuCollection(CreateBookingAppointment $appointment): BookingSkuCollection
	{
		$serviceIds = array_unique(array_map('intval', $appointment->getServiceIds()));
		if (empty($serviceIds))
		{
			throw new ServiceNotFoundException();
		}

		$skus = $this->serviceSkuProvider->get(
			$serviceIds,
			new SkuProviderConfig(onlyActiveAndAvailable: true),
		);
		if (count($skus) !== count($serviceIds))
		{
			throw new ServiceNotFoundException();
		}

		$bookingSkus = array_map(
			static fn ($sku) => (new BookingSku())->setId($sku->getId()),
			$skus,
		);

		return new BookingSkuCollection(...$bookingSkus);
	}

	private function findOrCreateContact(CreateBookingUser $user): int|null
	{
		return $this->contactSearcherService->findOrCreate(
			(new ContactDto($user->getName()))
				->setEmail($user->getEmail())
				->setPhone($user->getPhone())
		);
	}
}
