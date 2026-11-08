<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\MeasureTable;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(MeasureTable::class)]
class MeasureDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['update'])]
	#[Filterable, Sortable]
	public int $code;

	#[Required(['add'])]
	#[Editable(['update'])]
	#[Filterable, Sortable]
	public ?string $measureTitle;

	/**
	 * ORM field SYMBOL is stored in the column SYMBOL_RUS.
	 */
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $symbol;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $symbolIntl;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $symbolLetterIntl;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?bool $isDefault;
}
