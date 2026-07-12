<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

/**
 * Empty Avatar instance on write-path = explicit delete; empty Avatar never appears on read-path.
 */
class Avatar extends AbstractEntity
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

	public function hasEncodedFile(): bool
	{
		return $this->encodedFile !== null && $this->encodedFile !== '';
	}

	public function isEmptyPayload(): bool
	{
		$hasRoundTripData = $this->id !== null || ($this->url !== null && $this->url !== '');

		return $this->encodedFile === '' || (!$this->hasEncodedFile() && !$hasRoundTripData);
	}

	public function isRoundTripPayload(): bool
	{
		return !$this->hasEncodedFile() && !$this->isEmptyPayload();
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
