<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Chat extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $entityId = null,
		public readonly ?string $entityType = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			entityId: static::mapInteger($props, 'entityId'),
			entityType: static::mapString($props, 'entityType'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'entityId' => $this->entityId,
			'entityType' => $this->entityType,
		];
	}

	public function isTaskChat(): bool
	{
		return $this->entityType === \Bitrix\Tasks\V2\Internal\Integration\Im\Chat::ENTITY_TYPE;
	}
}
