<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant;

use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Entity\Sku\SkuCollection;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Catalog\SkuProviderConfig;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class ResourceSkuService
{
	public function __construct(
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly ServiceSkuProvider $serviceSkuProvider,
	)
	{
	}

	public function createSkuCollection(array $skuIds): BookingSkuCollection
	{
		$skus = $this->serviceSkuProvider->get(
			$skuIds,
			new SkuProviderConfig(onlyActiveAndAvailable: true),
		);
		if (count($skus) !== count($skuIds))
		{
			return new BookingSkuCollection();
		}

		$bookingSkus = array_map(
			static fn ($sku) => (new BookingSku())->setId($sku->getId()),
			$skus,
		);

		return new BookingSkuCollection(...$bookingSkus);
	}

	public function getResourceCollectionBySkuCollection(SkuCollection $skuCollection): ResourceCollection
	{
		if ($skuCollection->isEmpty())
		{
			return new ResourceCollection();
		}

		return $this->resourceRepository->getList(
			filter: new ResourceFilter(
				[
					'WITH_SKUS' => true,
					'HAS_SKUS' => $skuCollection->getEntityIds(),
				],
			),
			select: (new ResourceSelect(['SETTINGS']))->prepareSelect(),
		);
	}
}
