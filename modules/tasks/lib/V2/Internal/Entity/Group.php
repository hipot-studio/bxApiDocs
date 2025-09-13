<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\Min;

class Group extends AbstractEntity
{
	public function __construct(
		#[Min(0)]
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?File $image = null,
		/** @see \Bitrix\Socialnetwork\Item\Workgroup\Type */
		public readonly ?string $type = null,
		public readonly ?StageCollection $stages = null,
		public readonly ?bool $isVisible = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isScrum(): bool
	{
		return $this->type === 'scrum';
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'image' => $this->image?->toArray(),
			'type' => $this->type,
			'stages' => $this->stages?->toArray(),
			'isVisible' => $this->isVisible,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: isset($props['id']) ? (int)$props['id'] : null,
			name: $props['name'] ?? null,
			image: isset($props['image']) ? File::mapFromArray($props['image']) : null,
			type: $props['type'] ?? null,
			stages: isset($props['stages']) ? StageCollection::mapFromArray($props['stages']) : null,
			isVisible: $props['isVisible'] ?? null,
		);
	}
}
