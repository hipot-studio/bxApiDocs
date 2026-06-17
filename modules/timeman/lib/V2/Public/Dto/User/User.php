<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\User;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;

final class User implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly ?string $name = null,
		public readonly ?string $photo = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id', 0) ?? 0,
			name: static::mapString($props, 'name'),
			photo: static::mapString($props, 'photo'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'photo' => $this->photo,
		];
	}
}
