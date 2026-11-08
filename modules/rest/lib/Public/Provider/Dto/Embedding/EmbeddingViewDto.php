<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto\Embedding;

final class EmbeddingViewDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $description,
		public readonly string $groupName,
		public readonly ?array $icon,
		public readonly string $moduleId,
		public readonly string $entityType,
		public readonly string $entityId,
		public readonly EmbeddingOpenDto $open,
	)
	{
	}
}
