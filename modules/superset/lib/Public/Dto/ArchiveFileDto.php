<?php

namespace Bitrix\Superset\Public\Dto;

final class ArchiveFileDto
{
	public function __construct(
		private readonly string $tmpName,
		private readonly string $originalName = '',
		private readonly int $size = 0,
		private readonly string $contentType = '',
	)
	{
	}

	public static function fromArray(array $uploadedFile): self
	{
		return new self(
			tmpName: (string)($uploadedFile['tmp_name'] ?? $uploadedFile['tmpName'] ?? ''),
			originalName: (string)($uploadedFile['name'] ?? $uploadedFile['originalName'] ?? ''),
			size: (int)($uploadedFile['size'] ?? 0),
			contentType: (string)($uploadedFile['type'] ?? $uploadedFile['contentType'] ?? ''),
		);
	}

	public function getTmpName(): string
	{
		return $this->tmpName;
	}

	public function getOriginalName(): string
	{
		return $this->originalName;
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function getContentType(): string
	{
		return $this->contentType;
	}

	public function toArray(): array
	{
		return [
			'tmp_name' => $this->tmpName,
			'name' => $this->originalName,
			'size' => $this->size,
			'type' => $this->contentType,
		];
	}
}
