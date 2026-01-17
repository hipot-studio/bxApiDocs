<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\Rest\PlacementType;

class Placement extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $appId = null,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?array $options = null,
		public readonly ?PlacementType $type = null,
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
			appId: static::mapInteger($props, 'appId'),
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			options: static::mapArray($props, 'options'),
			type: PlacementType::tryFrom($props['type'] ?? ''),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'appId' => $this->appId,
			'title' => $this->title,
			'description' => $this->description,
			'options' => $this->options,
			'type' => $this->type?->value,
		];
	}
}
