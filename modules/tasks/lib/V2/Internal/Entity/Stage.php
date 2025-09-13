<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\Min;

class Stage extends AbstractEntity
{
	public function __construct(
		#[Min(0)]
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
			id: (int)($props['id'] ?? null),
			title: $props['title'] ?? null,
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
