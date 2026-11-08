<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\UserfieldDocumentMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Store document user fields (Engine UserField\Document).
 * Dynamic UF values travel in {@see $userFields} keyed as FIELD_{id} (update)
 * or UF_* / raw column names (list select).
 */
#[MappedBy(UserfieldDocumentMapper::class)]
class UserfieldDocumentDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['update'])]
	#[Editable(['update'])]
	#[Filterable, Sortable]
	public ?string $docType;

	/**
	 * @var array<string, mixed>|null
	 */
	#[Editable(['update'])]
	public ?array $userFields;
}
