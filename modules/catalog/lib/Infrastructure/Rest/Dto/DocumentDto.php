<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\StoreDocumentTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(StoreDocumentTable::class)]
class DocumentDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public string $docType;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?string $siteId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $responsibleId;

	#[Filterable, Sortable]
	public ?DateTime $dateModify;

	#[Filterable, Sortable]
	public ?DateTime $dateCreate;

	#[Filterable, Sortable]
	public ?int $createdBy;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $modifiedBy;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable]
	public string $currency;

	#[Filterable, Sortable]
	public ?string $status;

	#[Filterable, Sortable]
	public ?DateTime $dateStatus;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?DateTime $dateDocument;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $statusBy;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $total;

	#[Editable(['add', 'update'])]
	public ?string $commentary;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $title;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $docNumber;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $contractorId;
}
