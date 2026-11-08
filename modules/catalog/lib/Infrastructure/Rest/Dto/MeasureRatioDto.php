<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\MeasureRatioTable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Read-only surface: catalog.ratio.* has no write actions.
 */
#[OrmEntity(MeasureRatioTable::class)]
class MeasureRatioDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable, Sortable]
	public int $productId;

	#[Filterable, Sortable]
	public float $ratio;

	#[Filterable, Sortable]
	public ?bool $isDefault;
}
