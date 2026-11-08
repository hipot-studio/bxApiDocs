<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\GroupTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(GroupTable::class)]
class PriceTypeDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $name;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?bool $base;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $sort;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?string $xmlId;

	#[Filterable, Sortable]
	public ?DateTime $timestampX;

	#[Filterable, Sortable]
	public ?int $modifiedBy;

	#[Filterable, Sortable]
	public ?DateTime $dateCreate;

	#[Filterable, Sortable]
	public ?int $createdBy;
}
