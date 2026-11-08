<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\DocumentContractorMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Document↔CRM contractor binding. No #[OrmEntity]: CRM table is optional;
 * mapping is via {@see DocumentContractorMapper}, data access only after CRM load.
 */
#[MappedBy(DocumentContractorMapper::class)]
class DocumentContractorDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $documentId;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $entityTypeId;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $entityId;
}
