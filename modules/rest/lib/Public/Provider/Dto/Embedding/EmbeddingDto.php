<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto\Embedding;

final class EmbeddingDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $moduleId,
		public readonly string $entityType,
		public readonly string $entityId,
		public readonly string $placement,
		public readonly ?string $handler = null,
		public readonly ?int $userId = null,
	)
	{
	}
}
