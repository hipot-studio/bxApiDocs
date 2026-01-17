<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\File;

use Bitrix\Main\Type\Contract\Arrayable;

class File implements Arrayable
{
	public function __construct(
		private readonly int|null $id = null,
		private readonly string|null $url = null,
		private readonly string|null $encodedFile = null,
	)
	{
	}

	public static function createFromArray(array $props): self
	{
		return new self(
			$props['id'] ?? null,
			$props['url'] ?? null,
			$props['encodedFile'] ?? null,
		);
	}

	public function getId(): int|null
	{
		return $this->id;
	}

	public function getUrl(): string|null
	{
		return $this->url;
	}

	public function getEncodedFile(): string|null
	{
		return $this->encodedFile;
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
