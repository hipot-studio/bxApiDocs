<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class Epic extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $title = null,
		public readonly ?string $color = null,
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
			title: $props['name'] ?? null,
			color: $props['color'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'color' => $this->color,
		];
	}
}
