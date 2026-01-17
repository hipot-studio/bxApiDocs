<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuYandexRepository;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class ResourceSkuRelationsService
{
	public function __construct(
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly ResourceSkuRepository $resourceSkuRepository,
		private readonly ResourceSkuYandexRepository $resourceSkuYandexRepository
	)
	{
	}

	public function save(ResourceCollection $resourceCollection): void
	{
		$links = $this->getLinksArray($resourceCollection);
		if (empty($links))
		{
			throw new Exception('Service relations are not specified');
		}

		$this->resourceSkuYandexRepository->unlinkAll();
		$this->resourceSkuYandexRepository->linkByArray($links);
		$this->syncWithGlobalRelations($resourceCollection);
	}

	public function reset(): void
	{
		$this->resourceSkuYandexRepository->unlinkAll();
	}

	public function get(): ResourceCollection
	{
		$resourceFilter =
			$this->isSaved()
				? [
					'WITH_SKUS_YANDEX' => true,
				]
				: [
					'IS_MAIN' => true,
				]
		;

		$list = $this->resourceRepository->getList(
			filter: (new ResourceFilter($resourceFilter)),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
				'SKUS',
				'SKUS_YANDEX',
			]))->prepareSelect(),
		);

		$this->resourceRepository->withSkus($list);
		$this->resourceRepository->withSkusYandex($list);

		return $list;
	}

	public function isSaved(): bool
	{
		return !$this->resourceSkuYandexRepository->isEmpty();
	}

	private function getLinksArray(ResourceCollection $resourceCollection): array
	{
		$links = [];

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$skus = $resource->getSkuYandexCollection();

			foreach ($skus as $sku)
			{
				$links[] = [
					'RESOURCE_ID' => $resource->getId(),
					'SKU_ID' => $sku->getId(),
				];
			}
		}

		return $links;
	}

	private function syncWithGlobalRelations(ResourceCollection $resourceCollection): void
	{
		$enrichedResourceCollection = $this->resourceRepository
			->getList(
				filter: new ResourceFilter([
					'ID' => $resourceCollection->getEntityIds(),
				]),
				select: (new ResourceSelect([
					'SKUS',
					'SKUS_YANDEX',
				]))->prepareSelect(),
			);

		/** @var Resource $resource */
		foreach ($enrichedResourceCollection as $resource)
		{
			$this->resourceSkuRepository->link(
				$resource->getId(),
				$resource->getSkuYandexCollection()->diff(
					$resource->getSkuCollection()
				)
			);
		}
	}
}
