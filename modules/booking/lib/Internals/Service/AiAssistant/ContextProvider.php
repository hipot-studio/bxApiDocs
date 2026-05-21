<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use Bitrix\Booking\Entity\Resource\ResourceSku;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;

class ContextProvider
{
	public function __construct(
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly DateTimeService $dateTimeService,
	)
	{
	}

	public function getContext(int $bookingId): array|null
	{
		return [
			'booking' => $this->formatBooking(
				$this->getBooking($bookingId)
			),
			'resources' => $this->formatResourceCollection(
				$this->getResourceCollection()
			),
		];
	}

	private function getBooking(int $bookingId): Booking|null
	{
		$list = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'ID' => $bookingId,
				'INCLUDE_DELETED' => true,
			]),
			select: (new BookingSelect([
				'CLIENTS',
				'SKUS',
				'RESOURCES',
			]))->prepareSelect(),
		);

		$this->bookingRepository->withClientData($list);

		return $list->getFirstCollectionItem();
	}

	private function getResourceCollection(): ResourceCollection
	{
		$resourceCollection = $this->resourceRepository->getList(
			filter: (new ResourceFilter([
				'IS_MAIN' => true,
			])),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
				'SKUS',
			]))->prepareSelect(),
		);
		$this->resourceRepository->withSkus($resourceCollection);

		return $resourceCollection;
	}

	private function formatBooking(Booking|null $booking): array
	{
		if (!$booking)
		{
			return [];
		}

		return [
			'id' => $booking->getId(),
			'clientName' => $booking->getClientCollection()->getPrimaryClient()?->getName(),
			'resourceId' => $booking->getResourceCollection()->getPrimary()?->getId(),
			'serviceIds' => $booking->getSkuCollection()->getEntityIds(),
			'dateTime' => $this->dateTimeService->formatDateTime(
				$booking->getDatePeriod()->getDateFrom(),
			),
		];
	}

	private function formatResourceCollection(ResourceCollection $resourceCollection): array
	{
		$result = [];

		foreach ($resourceCollection as $resource)
		{
			$services = [];

			/** @var ResourceSku $skuItem */
			foreach ($resource->getSkuCollection() as $skuItem)
			{
				$services[] = [
					'id' => $skuItem->getId(),
					'name' => $skuItem->getName(),
					'price' => $skuItem->getPrice(),
					'currencyId' => $skuItem->getCurrencyId(),
				];
			}

			$result[] = [
				'id' => $resource->getId(),
				'name' => $resource->getName(),
				'typeName' => $resource->getType()?->getName(),
				'services' => $services,
			];
		}

		return $result;
	}
}
