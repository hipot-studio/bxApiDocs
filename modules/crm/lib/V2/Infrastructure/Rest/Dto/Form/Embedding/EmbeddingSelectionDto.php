<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\EmbeddingSelectionDtoMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/** One selected way to place a CRM form on a page. */
#[Title('Selected CRM form embedding option')]
#[MappedBy(EmbeddingSelectionDtoMapper::class)]
final class EmbeddingSelectionDto extends Dto
{
	public string $variant;

	public ?string $code;

	public string $url;
}
