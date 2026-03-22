<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Booking\Internals\Exception\InvalidSkuException;
use Bitrix\Booking\Internals\Integration\Catalog\SkuDataLoader;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuYandexRepository;
use Bitrix\Booking\Entity\Resource\ResourceCollection;

class ResourceSkuService
{
	public function __construct(
		private readonly ResourceSkuRepository $resourceSkuRepository,
		private readonly ResourceSkuYandexRepository $resourceSkuYandexRepository,
		private readonly SkuDataLoader$skuDataLoader,
		private readonly SkuService $skuService,
	)
	{
	}

	public function handleSkuRelations(
		Resource $resource,
		ResourceSkuCollection $newSkus,
	): void
	{
		$currentSkus = $resource->getSkuCollection();
		if ($newSkus->isEqual($currentSkus))
		{
			return;
		}

		if (!$currentSkus->isEmpty())
		{
			$unlink = $currentSkus->diff($newSkus);
			$this->resourceSkuRepository->unlink($resource->getId(), $unlink);
			$this->resourceSkuYandexRepository->unlink($resource->getId(), $unlink);
		}

		if (!$newSkus->isEmpty())
		{
			if (!$this->skuService->checkSkuExists($newSkus))
			{
				throw new InvalidSkuException();
			}

			$link = $newSkus->diff($currentSkus);
			$this->resourceSkuRepository->link($resource->getId(), $link);
		}

		$resource->setSkuCollection($newSkus);
	}

	public function loadForCollection(ResourceSkuCollection ...$collection): void
	{
		$this->skuDataLoader->loadForCollection(...$collection);
	}

	public function handleAllSkuRelations(
		ResourceCollection $currentResources,
		ResourceCollection $newResources,
	): void
	{
		foreach ($currentResources as $currentResource)
		{
			/** @var Resource $newResource */
			$newResource = $newResources->getByEntityId($currentResource->getId());

			$this->handleSkuRelations(
				$currentResource,
				$newResource === null
					? new ResourceSkuCollection()
					: $newResource->getSkuCollection()
				,
			);
		}
	}
}
