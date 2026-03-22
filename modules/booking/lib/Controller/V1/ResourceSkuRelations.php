<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Controller\V1\Response\ResourceSkuRelationsService;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\ResourceSkuService;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use Bitrix\Main\Engine\CurrentUser;

class ResourceSkuRelations extends BaseController
{
	private ServiceSkuProvider $serviceSkuProvider;
	private ResourceSkuService $resourceSkuService;
	private ResourceRepositoryInterface $resourceRepository;

	protected function init()
	{
		parent::init();

		$this->serviceSkuProvider = Container::getCatalogServiceSkuProvider();
		$this->resourceSkuService = Container::getResourceSkuService();
		$this->resourceRepository = Container::getResourceRepository();
	}

	public function getAction(CurrentUser $currentUser): ResourceSkuRelationsService\GetResponse|null
	{
		try
		{
			$resourceCollection = $this->resourceRepository->getList(
				select: (new ResourceSelect([
					'TYPE',
					'DATA',
					'SKUS',
				]))->prepareSelect(),
			);

			$this->resourceRepository->withSkus($resourceCollection);

			return new ResourceSkuRelationsService\GetResponse(
				catalogPermissions: [
					'read' => $this->serviceSkuProvider->checkCatalogReadAccess((int)$currentUser->getId()),
				],
				resources: $resourceCollection,
			);
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}

	public function saveAction(array $resources): array|null
	{
		try
		{
			$newResources = ResourceCollection::mapFromArray($resources);
			if ($newResources->isEmpty())
			{
				return [];
			}

			$currentResources = $this->resourceRepository->getList(
				filter: (new ResourceFilter([
					'ID' => $newResources->getEntityIds(),
				])),
				select: (new ResourceSelect([
					'TYPE',
					'DATA',
					'SKUS',
					'SKUS_YANDEX',
				]))->prepareSelect(),
			);

			$this->resourceRepository->withSkus($currentResources);
			$this->resourceRepository->withSkusYandex($currentResources);

			$this->resourceSkuService->handleAllSkuRelations(
				currentResources: $currentResources,
				newResources: $newResources,
			);

			return [];
		}
		catch (Exception $exception)
		{
			$this->addError(ErrorBuilder::buildFromException($exception));

			return null;
		}
	}
}
