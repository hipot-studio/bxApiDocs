<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\File;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Department extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?File $image = null,
		public readonly ?string $accessCode = null,
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
			name: static::mapString($props, 'name'),
			image: static::mapEntity($props, 'image', File::class),
			accessCode: static::mapString($props, 'accessCode'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'image' => $this->image?->toArray(),
			'accessCode' => $this->accessCode,
		];
	}
}
