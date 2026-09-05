<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\CrmForm;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Service\CrmForm\CrmFormService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Web\Uri;

class PublicForm extends Controller
{
	private const RESOURCE_ICON_PATH = '/bitrix/js/booking/crm-forms/field/images/resource-icon.svg';

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
	public function getResourcesWithSkusAction(array $resources, int $formId = 0, string $sec = ''): array|null
	{
		try
		{
			return $this->getResponseByResourceCollection(
				$this->crmFormService->getPublicResourceCollectionWithSkus($formId, $sec, $resources),
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getResourcesAction(array $ids = [], int $formId = 0, string $sec = ''): array|null
	{
		try
		{
			return $this->getResponseByResourceCollection(
				$this->crmFormService->getPublicResourceCollection($formId, $sec, $ids),
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getAutoSelectionDataAction(
		string $timezone,
		array $resourceIds = [],
		int $formId = 0,
		string $sec = '',
	): array|null
	{
		try
		{
			return $this->crmFormService
				->getPublicAutoSelectionData($formId, $sec, $timezone, $resourceIds)
				->toArray()
			;
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getOccupancyAction(array $ids, int $dateTs, int $formId = 0, string $sec = ''): array|null
	{
		try
		{
			$bookingCollection = $this->crmFormService->getPublicBookingCollectionForOccupancy(
				$formId,
				$sec,
				$ids,
				$dateTs,
			);

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
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

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
				'avatarUrl' => $this->normalizeAvatarUrl($resource->getAvatar()?->getUrl()),
				'description' => $resource->getDescription(),
				'skus' => $resource->getSkuCollection(),
			];
		}

		return $response;
	}

	private function normalizeAvatarUrl(string|null $avatarUrl): string
	{
		if ($avatarUrl === null || $avatarUrl === '')
		{
			$avatarUrl = self::RESOURCE_ICON_PATH;
		}

		return (string)(new Uri($avatarUrl))->toAbsolute();
	}
}
