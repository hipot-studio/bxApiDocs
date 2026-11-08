<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Epic extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $title = null,
		public readonly ?string $color = null,
		public readonly bool $isRestricted = false,
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
			title: static::mapString($props, 'name'),
			color: static::mapString($props, 'color'),
			isRestricted: static::mapBool($props, 'isRestricted', false) ?? false,
		);
	}

	public function toArray(): array
	{
		if ($this->isRestricted)
		{
			return [
				'id' => $this->id,
				'isRestricted' => true,
			];
		}

		return [
			'id' => $this->id,
			'title' => $this->title,
			'color' => $this->color,
			'isRestricted' => $this->isRestricted,
		];
	}
}
