<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\CatalogMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Trade catalog (iblock marked as a catalog). No #[OrmEntity]: rows come from \CCatalog::GetList(),
 * which joins b_catalog_iblock with b_iblock, and the table primary key is IBLOCK_ID, not ID.
 * {@see $id} always repeats {@see $iblockId}, kept for V1 response parity.
 */
#[MappedBy(CatalogMapper::class)]
class CatalogDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $iblockId;

	/** Y|N */
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $yandexExport;

	/** Y|N */
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $subscription;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public int $vatId;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public int $productIblockId;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public int $skuPropertyId;

	#[Filterable, Sortable]
	public string $iblockTypeId;

	#[Filterable, Sortable]
	public string $lid;

	#[Filterable, Sortable]
	public string $name;
}
