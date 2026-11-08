<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\VatTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(VatTable::class)]
class VatDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable, Sortable]
	public ?DateTime $timestampX;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?bool $active;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $sort;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $name;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $rate;
}
