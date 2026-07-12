<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class Avatar implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $url = null,
		public readonly ?string $encodedFile = null,
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
			url: static::mapString($props, 'url'),
			encodedFile: static::mapString($props, 'encodedFile'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'url' => $this->url,
			'encodedFile' => $this->encodedFile,
		];
	}
}
