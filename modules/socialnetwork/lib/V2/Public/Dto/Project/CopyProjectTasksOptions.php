<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class CopyProjectTasksOptions implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?bool $enabled = null,
		public readonly ?bool $robots = null,
	)
	{
	}

	public function getId(): ?int
	{
		return null;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			enabled: static::mapBool($props, 'enabled'),
			robots: static::mapBool($props, 'robots'),
		);
	}

	public function toArray(): array
	{
		return [
			'enabled' => $this->enabled,
			'robots' => $this->robots,
		];
	}
}
