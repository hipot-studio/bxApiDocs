<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\File;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class MemberEntity extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?MemberEntityType $type = null,
		public readonly ?bool $withChildNodes = null,
		public readonly ?string $name = null,
		public readonly ?File $image = null,
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
			type: static::mapBackedEnum($props, 'type', MemberEntityType::class),
			withChildNodes: static::mapBool($props, 'withChildNodes'),
			name: static::mapString($props, 'name'),
			image: static::mapEntity($props, 'image', File::class),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type?->value,
			'withChildNodes' => $this->withChildNodes,
			'name' => $this->name,
			'image' => $this->image?->toArray(),
		];
	}
}
