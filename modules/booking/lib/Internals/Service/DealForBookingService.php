<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Entity\ExternalData\ExternalDataCollection;
use Bitrix\Booking\Entity\ExternalData\ItemType\CrmDealItemType;
use Bitrix\Booking\Internals\Exception\PermissionDenied;
use Bitrix\Booking\Internals\Integration\Crm\DealService;
use Bitrix\Booking\Internals\Model\Enum\EntityType;
use Bitrix\Booking\Internals\Repository\ORM\BookingExternalDataRepository;
use Bitrix\Booking\Internals\Repository\ORM\BookingSkuRepository;

class DealForBookingService
{
	public function __construct(
		private readonly DealService $dealService,
		private readonly BookingSkuRepository $bookingSkuRepository,
		private readonly BookingExternalDataRepository $bookingExternalDataRepository,
	)
	{
	}

	public function onBookingUpdated(Booking $prevBooking, Booking $newBooking): void
	{
		$prevBookingDeal = $prevBooking
			->getExternalDataCollection()
			->filterByType((new CrmDealItemType())->buildFilter())
			->getFirstCollectionItem()
		;
		$newBookingDeal = $newBooking
			->getExternalDataCollection()
			->filterByType((new CrmDealItemType())->buildFilter())
			->getFirstCollectionItem()
		;

		if (!$prevBookingDeal && !$newBookingDeal)
		{
			return;
		}

		$dealChanged = $prevBookingDeal?->getValue() !== $newBookingDeal?->getValue();

		$prevSkus = $prevBooking->getSkuCollection();
		if (!$prevSkus->isEmpty())
		{
			$toDeleteSkus = $dealChanged
				? $prevSkus
				: $prevSkus->diff($newBooking->getSkuCollection())
			;
			$productRowIds = array_filter(
				array_map(
					static fn(BookingSku $sku) => $sku->getProductRowId(),
					$toDeleteSkus->getCollectionItems()
				)
			);
			if (!empty($productRowIds))
			{
				$this->dealService->deleteProductsFromDeal($productRowIds);
			}
		}

		// check there is new skus not linked to product rows of same deal
		$newBookingSkus = $newBooking->getSkuCollection();
		$newSkus = new BookingSkuCollection();
		foreach ($newBookingSkus as $bookingSku)
		{
			if (!$dealChanged && $bookingSku->getProductRowId())
			{
				continue;
			}

			$newSkus->add($bookingSku);
		}

		if (!$newBookingDeal || $newSkus->isEmpty())
		{
			return;
		}

		$skuProductRowMap = $this->dealService->addProductsToDeal(
			dealId: (int)$newBookingDeal->getValue(),
			skuIds: $newSkus->getEntityIds(),
		);
		if (empty($skuProductRowMap))
		{
			return;
		}

		$this->bookingSkuRepository->update($newBooking->getId(), $skuProductRowMap);

		return;
	}

	public function createAndLinkDeal(Booking $booking, int $userId): void
	{
		$existDeal = $booking
			->getExternalDataCollection()
			->filterByType((new CrmDealItemType())->buildFilter())
			->getFirstCollectionItem()
		;

		if ($existDeal)
		{
			return;
		}

		$dealId = $this->dealService->createDealForBooking($booking, $userId);
		if (!$dealId)
		{
			throw new PermissionDenied('can not create deal');
		}

		$skuIds = $booking->getSkuCollection()->getEntityIds();
		if (!empty($skuIds))
		{
			$skuProductMap = $this->dealService->addProductsToDeal($dealId, $skuIds);
			$this->bookingSkuRepository->update($booking->getId(), $skuProductMap);
		}
		$this->attachBookingToDeal($dealId, $booking);
	}

	private function attachBookingToDeal(int $dealId, Booking $booking): void
	{
		$dealItem = (new CrmDealItemType())->createItem()->setValue((string)$dealId);
		$booking->getExternalDataCollection()->add($dealItem);
		$this->bookingExternalDataRepository->link(
			entityId: $booking->getId(),
			entityType: EntityType::Booking,
			collection: new ExternalDataCollection($dealItem),
		);
	}
}
