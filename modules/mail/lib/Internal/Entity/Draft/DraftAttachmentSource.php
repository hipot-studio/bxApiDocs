<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Entity\Draft;

final class DraftAttachmentSource
{
	private const ALLOWED_SOURCES = ['draft', 'disk', 'upload', 'attached'];

	public function __construct(
		public readonly string $source,
		public readonly string $id,
	)
	{
		if (!in_array($source, self::ALLOWED_SOURCES, true) || trim($id) === '')
		{
			throw new \InvalidArgumentException('Invalid draft attachment source.');
		}
	}

	public static function fromArray(array $source): self
	{
		return new self((string)($source['source'] ?? ''), (string)($source['id'] ?? ''));
	}

	public function toArray(): array
	{
		return [
			'source' => $this->source,
			'id' => $this->id,
		];
	}
}
