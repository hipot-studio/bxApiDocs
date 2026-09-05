<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\CrmForm;

use Bitrix\Booking\Controller\V1\BaseController;
use Bitrix\Booking\Controller\V1\Response\ResourceSkuRelationsService\GetResponse;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuCreator;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Service\CrmForm\CrmFormService;
use Bitrix\Main\Engine\CurrentUser;

class SettingsForm extends BaseController
{
	private ServiceSkuProvider $serviceSkuProvider;
	private CrmFormService $crmFormService;

	protected function init()
	{
		parent::init();

		$this->serviceSkuProvider = Container::getCatalogServiceSkuProvider();
		$this->crmFormService = Container::getCrmFormService();
	}

	/**
	 * @param CurrentUser $currentUser
	 * @param array{
	 *     "id": string,
	 *     "skus": string[]
	 * } $resources
	 * @return GetResponse|null
	 */
	public function getFormSpecificResourceSkuRelationsAction(
		CurrentUser $currentUser,
		array $resources,
	): GetResponse|null
	{
		try
		{
			return new GetResponse(
				catalogPermissions: [
					'read' => $this->serviceSkuProvider->checkCatalogReadAccess((int)$currentUser->getId()),
				],
				resources: $this->crmFormService->getResourceCollectionWithSkus($resources),
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getDefaultResourceSkuRelationsAction(CurrentUser $currentUser): GetResponse|null
	{
		try
		{
			return new GetResponse(
				catalogPermissions: [
					'read' => $this->serviceSkuProvider->checkCatalogReadAccess((int)$currentUser->getId()),
				],
				resources: $this->crmFormService->getDefaultResourceCollectionWithSkus(),
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
			$resources = [];
			/** @var Resource $resource */
			foreach ($this->crmFormService->getResourceCollection($ids) as $resource)
			{
				$resources[] = [
					'id' => $resource->getId(),
					'name' => $resource->getName(),
					'typeName' => $resource->getType()?->getName(),
					'slotRanges' => $resource->getSlotRanges(),
				];
			}

			return $resources;
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function getCatalogSkuEntityOptionsAction(CurrentUser $currentUser): array
	{
		return (new ServiceSkuCreator())->getEntitySelectorEntityOptions((int)$currentUser->getId());
	}
}
