<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class ProjectTag extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?string $name = null,
	)
	{
	}

	public function getId(): ?string
	{
		return $this->name;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			name: static::mapString($props, 'name'),
		);
	}

	public function toArray(): array
	{
		return [
			'name' => $this->name,
		];
	}
}
