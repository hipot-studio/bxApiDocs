<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto\Embedding;

final class EmbeddingMenuItemDto
{
	public function __construct(
		public readonly int $id,
		public readonly ?int $appId,
		public readonly string $placement,
		public readonly string $bindingTitle,
		public readonly string $bindingGroupName,
		public readonly string $leftMenuTitle,
		public readonly string $applicationTitle,
		public readonly string $defaultTitle,
		public readonly string $url,
	)
	{
	}
}
