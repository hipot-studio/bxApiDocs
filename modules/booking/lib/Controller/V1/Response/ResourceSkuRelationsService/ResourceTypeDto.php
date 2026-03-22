<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1\Response\ResourceSkuRelationsService;

use Bitrix\Booking\Entity\ResourceType\ResourceType;
use Bitrix\Main\Type\Contract\Arrayable;

class ResourceTypeDto implements \JsonSerializable, Arrayable
{
	public function __construct(
		public readonly int $id,
	)
	{
	}

	public static function fromEntity(ResourceType $resourceType): self
	{
		return new self(
			id: $resourceType->getId(),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
