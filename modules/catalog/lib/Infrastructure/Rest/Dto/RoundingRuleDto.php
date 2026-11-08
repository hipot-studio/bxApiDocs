<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\RoundingTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Authorship and dates are filled by RoundingTable handlers, so they carry no Editable.
 */
#[OrmEntity(RoundingTable::class)]
class RoundingRuleDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $catalogGroupId;

	// Price threshold of the rule: 0 is the default rule, negative values are rejected by the service.
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $price;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $roundType;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $roundPrecision;

	#[Filterable, Sortable]
	public ?int $createdBy;

	#[Filterable, Sortable]
	public ?DateTime $dateCreate;

	#[Filterable, Sortable]
	public ?int $modifiedBy;

	// The column is DATE_MODIFY, not TIMESTAMP_X promised by the RoundingTable docblock.
	#[Filterable, Sortable]
	public ?DateTime $dateModify;
}
