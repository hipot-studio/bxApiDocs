<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class Attachment extends AbstractEntity
{

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $fileId = null,
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
			fileId: $props['fileId'] ?? null,
		);
	}

	public function toArray()
	{
		return [
			'id' => $this->id,
			'fileId' => $this->fileId,
		];
	}
}
