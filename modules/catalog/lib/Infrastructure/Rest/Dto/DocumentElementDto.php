<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\StoreDocumentElementTable;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(StoreDocumentElementTable::class)]
class DocumentElementDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $docId;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $storeFrom;

	#[Editable(['add', 'update'])]
	#[Filterable]
	public ?int $storeTo;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $elementId;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $amount;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $purchasingPrice;
}
