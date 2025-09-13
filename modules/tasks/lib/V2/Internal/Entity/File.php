<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class File extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $src = null,
		public readonly ?string $name = null,
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?int $size = null,
		public readonly ?string $subDir = null,
		public readonly ?string $contentType = null,
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
			id: $props['id'] ?? null,
			src: $props['src'] ?? null,
			name: $props['name'] ?? null,
			width: $props['width'] ?? null,
			height: $props['height'] ?? null,
			size: $props['size'] ?? null,
			subDir: $props['subDir'] ?? null,
			contentType: $props['contentType'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'src' => $this->src,
			'name' => $this->name,
			'width' => $this->width,
			'height' => $this->height,
			'size' => $this->size,
			'subDir' => $this->subDir,
			'contentType' => $this->contentType,
		];
	}
}