<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\CrmForm;

use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\CrmForm\CrmFormService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Booking\Entity\Resource\Resource;

class PublicForm extends Controller
{
	private CrmFormService $crmFormService;

	protected function init(): void
	{
		$this->crmFormService = Container::getCrmFormService();

		parent::init();
	}

	public function getDefaultPreFilters(): array
	{
		return [];
	}

	protected function getDefaultPostFilters(): array
	{
		return [
			new ActionFilter\Cors(),
		];
	}

	/**
	 * @param array{
	 *     "id": string,
	 *     "skus": string[]
	 * } $resources
	 * @return array|null
	 */
	public function getResourcesWithSkusAction(array $resources): array|null
	{
		try
		{
			return $this->getResponseByResourceCollection(
				$this->crmFormService->getResourceCollectionWithSkus($resources)
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getResourcesAction(array $ids = []): array|null
	{
		try
		{
			return $this->getResponseByResourceCollection(
				$this->crmFormService->getResourceCollection($ids)
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getAutoSelectionDataAction(string $timezone, array $resourceIds = []): array|null
	{
		try
		{
			return $this->crmFormService->getAutoSelectionData($timezone, $resourceIds)->toArray();
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getOccupancyAction(array $ids, int $dateTs): array|null
	{
		try
		{
			$bookingCollection = $this->crmFormService->getBookingCollectionForOccupancy($ids, $dateTs);

			$response = [];
			foreach ($bookingCollection as $booking)
			{
				$response[] = [
					'resourcesIds' => $booking->getResourceCollection()->getEntityIds(),
					'fromTs' => $booking->getDatePeriod()->getDateFrom()->getTimestamp(),
					'toTs' => $booking->getDatePeriod()->getDateTo()->getTimestamp(),
				];
			}

			return $response;
		}
		catch (Exception $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));

			return null;
		}
	}

	private function getResponseByResourceCollection(ResourceCollection $resourceCollection): array
	{
		$response = [];

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$response[] = [
				'id' => $resource->getId(),
				'name' => $resource->getName(),
				'typeName' => $resource->getType()?->getName(),
				'slotRanges' => $resource->getSlotRanges(),
				'avatarUrl' => $resource->getAvatar()?->getUrl(),
				'description' => $resource->getDescription(),
				'skus' => $resource->getSkuCollection(),
			];
		}

		return $response;
	}
}
