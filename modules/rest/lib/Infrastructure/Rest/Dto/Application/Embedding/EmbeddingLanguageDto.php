<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding;

use Bitrix\Rest\V3\Dto\Dto;

class EmbeddingLanguageDto extends Dto
{
	public string $languageId;
	public ?string $title = null;
	public ?string $description = null;
	public ?string $groupName = null;
}