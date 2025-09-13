<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\Min;

class Flow extends AbstractEntity
{
	public function __construct(
		#[Min(0)]
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?int $efficiency = null,
		public readonly ?Group $group = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'efficiency' => $this->efficiency,
			'group' => $this->group?->toArray(),
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: $props['id'] ?? null,
			name: $props['name'] ?? null,
			efficiency: $props['efficiency'] ?? null,
			group: isset($props['group']) ? Group::mapFromArray($props['group']) : null,
		);
	}
}
