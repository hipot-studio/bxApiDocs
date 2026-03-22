<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Response\ResourceSkuRelationsService;

use Bitrix\Booking\Entity\File\File;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceSkuCollection;
use Bitrix\Main\Type\Contract\Arrayable;

class ResourceDto implements \JsonSerializable, Arrayable
{
	public function __construct(
		public readonly int $id,
		public readonly ResourceTypeDto $type,
		public readonly string $name,
		public readonly ResourceSkuCollection $skus,
		public readonly File|null $avatar,
	)
	{
	}

	public static function fromEntity(Resource $resource): self
	{
		return new self(
			id: $resource->getId(),
			type: ResourceTypeDto::fromEntity($resource->getType()),
			name: $resource->getName(),
			skus: $resource->getSkuCollection(),
			avatar: $resource->getAvatar(),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type->toArray(),
			'name' => $this->name,
			'skus' => $this->skus->toArray(),
			'avatar' => $this->avatar?->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
