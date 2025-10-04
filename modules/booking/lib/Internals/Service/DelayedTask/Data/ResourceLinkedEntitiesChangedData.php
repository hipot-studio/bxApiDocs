<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\DelayedTask\Data;

use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityCollection;
use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskType;

class ResourceLinkedEntitiesChangedData implements DataInterface
{
	private DelayedTaskType $type = DelayedTaskType::ResourceLinkedEntitiesChanged;

	public function __construct(
		public readonly int $resourceId,
		public readonly ResourceLinkedEntityCollection|null $deleted = null,
		public readonly ResourceLinkedEntityCollection|null $added = null,
	)
	{
	}

	public function getType(): DelayedTaskType
	{
		return $this->type;
	}

	public function toArray(): array
	{
		return [
			'resourceId' => $this->resourceId,
			'deleted' => $this->deleted?->toArray(),
			'added' => $this->added?->toArray(),
		];
	}

	public static function mapFromArray(array $params): self
	{
		return new self(
			$params['resourceId'],
			$params['deleted'] ?? null ? ResourceLinkedEntityCollection::mapFromArray($params['deleted']) : null,
			$params['added'] ?? null ? ResourceLinkedEntityCollection::mapFromArray($params['added']) : null,
		);
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
