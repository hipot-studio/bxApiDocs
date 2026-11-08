<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Dto\Embedding;

final class EmbeddingLanguageDto
{
	public function __construct(
		public readonly string $languageId,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?string $groupName = null,
	)
	{
	}
}
