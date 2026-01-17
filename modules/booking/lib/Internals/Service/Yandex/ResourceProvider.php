<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Yandex;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Exception\Yandex\InternalErrorException;
use Bitrix\Booking\Internals\Exception\Yandex\ServiceNotFoundException;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Catalog\SkuProviderConfig;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\Yandex\Dto\Api\Collection\ResourceCollection;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;
use Bitrix\Booking\Internals\Service\Yandex;
use Bitrix\Main\Web\Uri;

class ResourceProvider
{
	public function __construct(
		private readonly CompanyRepository $companyRepository,
		private readonly ResourceRepositoryInterface $resourceRepository,
		private readonly ServiceSkuProvider $serviceSkuProvider
	)
	{
	}

	public function getResources(string $companyId, array $serviceIds = []): ResourceCollection
	{
		$result = new ResourceCollection();

		$company = $this->companyRepository->getById($companyId);
		if (!$company)
		{
			throw new InternalErrorException('Company not found');
		}

		$serviceIds = array_unique(array_map('intval', $serviceIds));
		if (!empty($serviceIds))
		{
			$skus = $this->serviceSkuProvider->get(
				$serviceIds,
				new SkuProviderConfig(onlyActiveAndAvailable: true),
			);
			if (count($skus) !== count($serviceIds))
			{
				throw new ServiceNotFoundException();
			}
		}

		$resourceCollection = $this->resourceRepository->getList(
			filter: (new ResourceFilter([
				'WITH_SKUS_YANDEX' => true,
				'HAS_SKUS_YANDEX' => $serviceIds,
			])),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
			]))->prepareSelect(),
		);

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			if (
				$resource->getId() === null
				|| $resource->getName() === null
			)
			{
				continue;
			}

			$resourceDtoItem = (new Yandex\Dto\Api\Item\Resource(
				(string)$resource->getId(),
				(string)$resource->getName(),
			))
				->setDescription($resource->getType()?->getName())
				->setInformation($resource->getDescription())
			;

			$avatar = $resource->getAvatar();
			if ($avatar?->getUrl())
			{
				$resourceDtoItem->setImage(
					(string)(new Uri($avatar->getUrl()))->toAbsolute()
				);
			}

			$result->add($resourceDtoItem);
		}

		return $result;
	}
}
