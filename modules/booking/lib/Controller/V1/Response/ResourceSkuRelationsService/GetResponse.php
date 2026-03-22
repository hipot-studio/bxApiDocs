<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Response\ResourceSkuRelationsService;

use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Main\Type\Contract\Arrayable;

class GetResponse implements \JsonSerializable, Arrayable
{
	public function __construct(
		public readonly array $catalogPermissions,
		public readonly ResourceCollection $resources,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'catalogPermissions' => $this->catalogPermissions,
			'resources' => array_map(
				static fn (Resource $resource): ResourceDto => ResourceDto::fromEntity($resource),
				$this->resources->getCollectionItems(),
			),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
