<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant\Mapper;

use Bitrix\Booking\Entity\Resource\Resource;

class ResourceMapper
{
	public function __construct(
		private readonly SkuMapper $skuMapper,
	)
	{
	}

	public function mapFromEntity(Resource $resource, bool $withServices = false): array
	{
		$result = [
			'id' => $resource->getId(),
			'name' => $resource->getName(),
			'typeName' => $resource->getType()?->getName(),
		];

		if ($withServices)
		{
			$services = [];
			foreach ($resource->getSkuCollection() as $skuItem)
			{
				$services[] = $this->skuMapper->mapFromEntity($skuItem);
			}
			$result['services'] = $services;
		}

		return $result;
	}
}
