<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form\EmbeddingDtoMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The direct ways a CRM form can be placed on a page of somebody else.
 *
 * The security code of the form lives inside the links and the embed snippets and has no field of
 * its own; nothing of the spam protection, of the stored settings blob or of the form editor
 * reaches this contract.
 */
#[Title('CRM form embedding options')]
#[MappedBy(EmbeddingDtoMapper::class)]
final class EmbeddingDto extends Dto
{
	public bool $formActive;

	/** While the resources are not built every variant comes back without an embed code. */
	public bool $resourcesReady;

	public string $publicUrl;

	public EmbeddingVariantDto $inline;

	#[Editable(['update'])]
	public EmbeddingVariantDto $click;

	#[Editable(['update'])]
	public EmbeddingVariantDto $auto;
}
