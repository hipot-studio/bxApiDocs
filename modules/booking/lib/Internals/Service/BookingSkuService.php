<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Internals\Exception\InvalidSkuException;
use Bitrix\Booking\Internals\Integration\Catalog\SkuDataLoader;
use Bitrix\Booking\Internals\Integration\Crm\ProductRowDataLoader;
use Bitrix\Booking\Internals\Repository\ORM\BookingSkuRepository;

class BookingSkuService
{
	public function __construct(
		private readonly BookingSkuRepository $skuRepository,
		private readonly SkuDataLoader $skuDataLoader,
		private readonly ProductRowDataLoader $productRowDataLoader,
	)
	{
	}

	public function handleSkuRelations(Booking $newBooking, BookingSkuCollection $currentSkus): void
	{
		$newSkus = $newBooking->getSkuCollection();

		if ($newSkus->isEqual($currentSkus))
		{
			return;
		}

		if (!$currentSkus->isEmpty())
		{
			$toDelete = $currentSkus->diff($newSkus);
			$this->skuRepository->unLink($newBooking->getId(), $toDelete);
		}

		if (!$newSkus->isEmpty())
		{
			$this->loadForCollection($newSkus);
			if (!empty(
				array_filter(
					$newSkus->getCollectionItems(),
					static fn (BookingSku $sku) => empty($sku->getPermissions())
				))
			)
			{
				throw new InvalidSkuException();
			}

			$toAdd = $newSkus->diff($currentSkus);
			$this->skuRepository->link($newBooking->getId(), $toAdd);
		}
	}

	public function loadForCollection(BookingSkuCollection ...$collection): void
	{
		// load from catalog if not connected with crm deal
		$this->skuDataLoader->loadForCollection(...$collection);
		// load price data from crm deal if connected
		$this->productRowDataLoader->loadForCollection(...$collection);
	}
}
