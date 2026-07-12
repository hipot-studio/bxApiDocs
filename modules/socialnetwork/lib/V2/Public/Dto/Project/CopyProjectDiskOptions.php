<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class CopyProjectDiskOptions implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?bool $enabled = null,
		public readonly ?bool $withFiles = null,
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
			withFiles: static::mapBool($props, 'withFiles'),
		);
	}

	public function toArray(): array
	{
		return [
			'enabled' => $this->enabled,
			'withFiles' => $this->withFiles,
		];
	}
}
