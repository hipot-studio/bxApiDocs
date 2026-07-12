<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class Permission extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?string $feature = null,
		public readonly array $permissions = [],
	)
	{
	}

	public function getId(): ?string
	{
		return $this->feature;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			feature: static::mapString($props, 'feature'),
			permissions: static::mapArray($props, 'permissions') ?? [],
		);
	}

	public function toArray(): array
	{
		return [
			'feature' => $this->feature,
			'permissions' => $this->permissions,
		];
	}
}
