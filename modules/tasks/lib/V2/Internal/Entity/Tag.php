<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\NotEmpty;

class Tag extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		#[NotEmpty]
		public readonly ?string $name = null,
		public readonly ?User $owner = null,
		public readonly ?Group $group = null,
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
			id: $props['id'] ?? null,
			name: $props['name'] ?? null,
			owner: isset($props['owner']) ? User::mapFromArray($props['owner']) : null,
			group: isset($props['group']) ? Group::mapFromArray($props['group']) : null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'owner' => $this->owner?->toArray(),
			'group' => $this->group?->toArray(),
		];
	}
}