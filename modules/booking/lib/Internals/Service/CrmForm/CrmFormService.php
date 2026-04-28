<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\CrmForm;

use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Entity\Resource\ResourceSku;
use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Catalog\Sku;
use Bitrix\Booking\Internals\Integration\Catalog\SkuProviderConfig;
use Bitrix\Booking\Internals\Repository\ORM\BookingRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceRepository;
use Bitrix\Booking\Internals\Service\Time;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class CrmFormService
{
	private const LOOK_AHEAD_DAYS_AUTO_SELECTION = 60;

	public function __construct(
		private readonly ResourceRepository $resourceRepository,
		private readonly BookingRepository $bookingRepository,
		private readonly ResourceAutoSelectionService $resourceAutoSelectionService,
		private readonly ServiceSkuProvider $serviceSkuProvider,
	)
	{
	}

	public function getResourceCollection(array $ids): ResourceCollection
	{
		if (empty($ids))
		{
			return new ResourceCollection();
		}

		return $this->resourceRepository->getList(
			filter: new ResourceFilter(['ID' => array_map('intval', $ids)]),
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
			filter: new ResourceFilter(['ID' => array_keys($resourceSkusMap),]),
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

		$resourceCollection = $this->resourceRepository->getList(
			filter: new ResourceFilter(
				empty($resourceIds)
					? []
					: ['ID' => array_map('intval', $resourceIds)]
			),
			select: (new ResourceSelect())->prepareSelect(),
		);

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
}
