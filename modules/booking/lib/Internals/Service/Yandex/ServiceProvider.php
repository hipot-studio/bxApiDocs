<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Exception\Yandex\InternalErrorException;
use Bitrix\Booking\Internals\Exception\Yandex\ResourceNotFoundException;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\ServiceCollection;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use Bitrix\Booking\Internals\Service\Yandex;
use Bitrix\Booking\Internals\Integration\Catalog;
use Bitrix\Main\Web\Uri;

class ServiceProvider
{
	public function __construct(
		private readonly CompanyRepository $companyRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly Catalog\ServiceSkuProvider $serviceSkuProvider
	)
	{
	}

	public function getServices(string $companyId, string $resourceId = null): ServiceCollection
	{
		$company = $this->companyRepository->getById($companyId);
		if (!$company)
		{
			throw new InternalErrorException('Company not found');
		}

		$resourceId = $resourceId !== null ? (int)$resourceId : null;
		if ($resourceId !== null)
        {
            $resource = $this->resourceRepository->getById($resourceId);
            if ($resource === null)
            {
                throw new ResourceNotFoundException();
            }
        }

		$serviceCollection = new ServiceCollection();
		$filter = [
			'WITH_SKUS_YANDEX' => true,
		];
		if ($resourceId !== null)
		{
			$filter['ID'] = $resourceId;
		}

		$resourceCollection = $this->resourceRepository->getList(
			filter: (new ResourceFilter($filter)),
			select: (new ResourceSelect([
				'DATA',
				'SETTINGS',
				'SKUS',
				'SKUS_YANDEX',
			]))->prepareSelect(),
		);

		if ($resourceCollection->isEmpty())
		{
			return $serviceCollection;
		}

		$service2ResourcesMap = [];
		$skus = $this->getSkus($resourceCollection, $service2ResourcesMap);

		foreach ($skus as $sku)
		{
			$serviceItem = (new Yandex\Dto\Api\Item\Service(
				(string)$sku->getId(),
				$sku->getName()
			));
			$serviceItem->setCategory($sku->getSection());

			if ($sku->getPrice() !== null && $sku->getCurrencyId() !== null)
			{
				$serviceItem->setPrice(
					new Yandex\Dto\Api\Item\PriceRange($sku->getCurrencyId(), $sku->getPrice(), $sku->getPrice())
				);
			}

			if ($sku->getImage())
			{
				$serviceItem->setImage(
					(string)(new Uri($sku->getImage()))->toAbsolute()
				);
			}

			$serviceResources = $service2ResourcesMap[$sku->getId()] ?? [];
			foreach ($serviceResources as $resourceId)
			{
				$resource = $resourceCollection->getByEntityId($resourceId);
				if (!$resource)
				{
					continue;
				}

				$serviceResourceItem = new Yandex\Dto\Api\Item\ServiceResource((string)$resource->getId());
				$range = $resource->getSlotRanges()->getFirstCollectionItem();
				if ($range)
				{
					$serviceResourceItem->setDurationSeconds(
						$range->getSlotSize() * Time::SECONDS_IN_MINUTE
					);
				}

				$serviceItem->addResource($serviceResourceItem);
			}

			$serviceCollection->add($serviceItem);
		}

		return $serviceCollection;
	}

	/**
	 * @param ResourceCollection $resourceCollection
	 * @param array $service2ResourcesMap
	 * @return Catalog\Sku[]
	 */
	private function getSkus(ResourceCollection $resourceCollection, array &$service2ResourcesMap): array
	{
		$result = [];

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			foreach ($resource->getSkuYandexCollection() as $sku)
			{
				$skuId = $sku->getId();

				if (!isset($service2ResourcesMap[$skuId]))
				{
					$service2ResourcesMap[$skuId] = [];
				}

				$service2ResourcesMap[$skuId][] = $resource->getId();
				$result[] = $skuId;
			}
		}

		$result = array_unique($result);
		if (empty($result))
		{
			return [];
		}

		return $this->serviceSkuProvider->get(
			$result,
			new Catalog\SkuProviderConfig(
				loadSections: true,
				onlyActiveAndAvailable: true,
			)
		);
	}
}
