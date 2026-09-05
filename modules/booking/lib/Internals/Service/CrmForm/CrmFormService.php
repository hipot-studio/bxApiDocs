<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\CrmForm;

use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Entity\Resource\ResourceSku;
use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Catalog\Sku;
use Bitrix\Booking\Internals\Integration\Catalog\SkuProviderConfig;
use Bitrix\Booking\Internals\Integration\Crm\WebForm\ResourceAccess;
use Bitrix\Booking\Internals\Integration\Crm\WebForm\ResourceAccessProvider;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class CrmFormService
{
	private const LOOK_AHEAD_DAYS_AUTO_SELECTION = 60;

	public function __construct(
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly BookingRepositoryInterface $bookingRepository,
		private readonly ResourceAutoSelectionService $resourceAutoSelectionService,
		private readonly ServiceSkuProvider $serviceSkuProvider,
		private readonly ResourceAccessProvider $resourceAccessProvider,
	)
	{
	}

	public function getPublicResourceCollection(int $formId, string $securityCode, array $ids): ResourceCollection
	{
		if (!$this->hasFormContext($formId, $securityCode))
		{
			return $this->getResourceCollection($ids);
		}

		$resourceAccess = $this->resourceAccessProvider->get($formId, $securityCode);

		return $this->getResourceCollection($resourceAccess->filterResourceIds($ids));
	}

	/**
	 * @param array{
	 *     "id": string,
	 *     "skus": string[]
	 * } $resources
	 * @return ResourceCollection
	 * @throws Exception
	 */
	public function getPublicResourceCollectionWithSkus(
		int $formId,
		string $securityCode,
		array $resources,
	): ResourceCollection
	{
		if (!$this->hasFormContext($formId, $securityCode))
		{
			return $this->getResourceCollectionWithSkus($resources);
		}

		$resourceAccess = $this->resourceAccessProvider->get($formId, $securityCode);
		$resources = $resourceAccess->filterResourcesWithSkus($resources);
		if (empty($resources))
		{
			return new ResourceCollection();
		}

		return $this->getResourceCollectionWithSkus($resources);
	}

	public function getPublicAutoSelectionData(
		int $formId,
		string $securityCode,
		string $timezone,
		array $resourceIds = [],
	): ResourceAutoSelectionSearchResult
	{
		if (!$this->hasFormContext($formId, $securityCode))
		{
			return $this->getAutoSelectionData($timezone, $resourceIds);
		}

		$resourceAccess = $this->resourceAccessProvider->get($formId, $securityCode);
		$resourceIds = empty($resourceIds)
			? $resourceAccess->getResourceIds()
			: $resourceAccess->filterResourceIds($resourceIds);

		if (empty($resourceIds))
		{
			return new ResourceAutoSelectionSearchResult();
		}

		return $this->getAutoSelectionData($timezone, $resourceIds);
	}

	public function getPublicBookingCollectionForOccupancy(
		int $formId,
		string $securityCode,
		array $ids,
		int $dateTs,
	): BookingCollection
	{
		if (!$this->hasFormContext($formId, $securityCode))
		{
			return $this->getBookingCollectionForOccupancy($ids, $dateTs);
		}

		$resourceAccess = $this->resourceAccessProvider->get($formId, $securityCode);
		$ids = $resourceAccess->filterResourceIds($ids);
		if (empty($ids))
		{
			return new BookingCollection();
		}

		$bookingCollection = $this->getBookingCollectionForOccupancy($ids, $dateTs);
		$this->filterBookingResources($bookingCollection, $resourceAccess);

		return $bookingCollection;
	}

	public function getResourceCollection(array $ids): ResourceCollection
	{
		if (empty($ids))
		{
			return new ResourceCollection();
		}

		return $this->resourceRepository->getList(
			filter: new ResourceFilter([
				'ID' => array_map('intval', $ids),
				'SHORT_SLOTS_ONLY' => true,
			]),
			select: (new ResourceSelect())->prepareSelect(),
		);
	}

	/**
	 * @param array{
	 *     "id": string,
	 *     "skus": string[]
	 * } $resources
	 * @return ResourceCollection
	 * @throws Exception
	 */
	public function getResourceCollectionWithSkus(array $resources): ResourceCollection
	{
		$resourceSkusMap = $this->getResourceSkusMap($resources);
		if (empty($resourceSkusMap))
		{
			return new ResourceCollection();
		}

		$resourceCollection = $this->resourceRepository->getList(
			filter: new ResourceFilter([
				'ID' => array_keys($resourceSkusMap),
				'SHORT_SLOTS_ONLY' => true,
			]),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
			]))->prepareSelect(),
		);

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$skuCollection = new ResourceSkuCollection();
			if (isset($resourceSkusMap[$resource->getId()]))
			{
				/** @var Sku $sku */
				foreach ($resourceSkusMap[$resource->getId()] as $sku)
				{
					$skuCollection->add(
						(new ResourceSku())
							->setId($sku->getId())
							->setName($sku->getName())
							->setPrice($sku->getPrice())
							->setCurrencyId($sku->getCurrencyId())
					);
				}
			}

			$resource->setSkuCollection($skuCollection);
		}

		return $resourceCollection;
	}

	public function getDefaultResourceCollectionWithSkus(): ResourceCollection
	{
		$resourceCollection = $this->resourceRepository->getList(
			filter: new ResourceFilter([
				'SHORT_SLOTS_ONLY' => true,
			]),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
				'SKUS',
			]))->prepareSelect(),
		);

		$this->resourceRepository->withSkus($resourceCollection);

		return $resourceCollection;
	}

	public function getAutoSelectionData(
		string $timezone,
		array $resourceIds = []
	): ResourceAutoSelectionSearchResult
	{
		$currentTime = time();

		$searchPeriod = new DatePeriod(
			(new \DateTimeImmutable('@' . $currentTime))
				->setTimezone(new \DateTimeZone($timezone)),
			(new \DateTimeImmutable('@' . $currentTime + (Time::SECONDS_IN_DAY * self::LOOK_AHEAD_DAYS_AUTO_SELECTION)))
				->setTimezone(new \DateTimeZone($timezone))
		);

		$resourceFilter = ['SHORT_SLOTS_ONLY' => true];
		if (!empty($resourceIds))
		{
			$resourceFilter['ID'] = array_map('intval', $resourceIds);
		}

		$resourceCollection = $this->resourceRepository->getList(
			filter: new ResourceFilter($resourceFilter),
			select: (new ResourceSelect())->prepareSelect(),
		);

		if ($resourceCollection->isEmpty())
		{
			return new ResourceAutoSelectionSearchResult();
		}

		$bookingCollection = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'RESOURCE_ID' => $resourceCollection->getEntityIds(),
				'WITHIN' => [
					'DATE_FROM' => $searchPeriod->getDateFrom()->getTimestamp(),
					'DATE_TO' => $searchPeriod->getDateTo()->getTimestamp(),
				],
			]),
			select: (new BookingSelect(['RESOURCES']))->prepareSelect(),
		);

		return $this->resourceAutoSelectionService->search($searchPeriod, $resourceCollection, $bookingCollection);
	}

	public function getBookingCollectionForOccupancy(array $ids, int $dateTs): BookingCollection
	{
		$date = new \DateTimeImmutable('@' . $dateTs);
		$datePeriod = new DatePeriod(
			dateFrom: $date,
			dateTo: $date->add(new \DateInterval('P1D')), // add 1 day
		);

		return $this->bookingRepository->getList(
			filter: new BookingFilter([
				'RESOURCE_ID' => $ids,
				'WITHIN' => [
					'DATE_FROM' => $datePeriod->getDateFrom()->getTimestamp(),
					'DATE_TO' => $datePeriod->getDateTo()->getTimestamp(),
				],
			]),
			select: (new BookingSelect([
				'RESOURCES',
			]))->prepareSelect(),
		);
	}

	private function hasFormContext(int $formId, string $securityCode): bool
	{
		return $formId > 0 && $securityCode !== '';
	}

	private function getResourceSkusMap(array $resources): array
	{
		$result = [];

		$allSkuIds = [];
		foreach ($resources as $resource)
		{
			$allSkuIds = array_merge(
				$allSkuIds,
				array_map('intval', $resource['skus'] ?? [])
			);
		}

		$indexedSkus = [];
		$skus = $this->serviceSkuProvider->get(
			array_unique($allSkuIds),
			new SkuProviderConfig(
				onlyActiveAndAvailable: true,
			)
		);
		foreach ($skus as $sku)
		{
			$indexedSkus[$sku->getId()] = $sku;
		}

		foreach ($resources as $resource)
		{
			if (!isset($resource['id']))
			{
				continue;
			}

			$resourceSkus = [];
			foreach ($resource['skus'] ?? [] as $skuId)
			{
				if (isset($indexedSkus[$skuId]))
				{
					$resourceSkus[] = $indexedSkus[$skuId];
				}
			}

			if (!empty($resourceSkus))
			{
				$result[(int)$resource['id']] = $resourceSkus;
			}
		}

		return $result;
	}

	private function filterBookingResources(
		BookingCollection $bookingCollection,
		ResourceAccess $resourceAccess,
	): void
	{
		foreach ($bookingCollection as $booking)
		{
			$allowedResources = [];
			foreach ($booking->getResourceCollection() as $resource)
			{
				$resourceId = $resource->getId();
				if ($resourceId !== null && $resourceAccess->isResourceAllowed($resourceId))
				{
					$allowedResources[] = $resource;
				}
			}

			$booking->setResourceCollection(new ResourceCollection(...$allowedResources));
		}
	}
}
