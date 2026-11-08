<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\SectionMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Catalog iblock section. No #[OrmEntity]: rows come from \CIBlockSection::GetList(),
 * write goes through \CIBlockSection, so section tree side effects stay in the domain.
 */
#[MappedBy(SectionMapper::class)]
class SectionDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable]
	public int $iblockId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $name;

	/** Parent section. The domain filters it as SECTION_ID and does not sort by it. */
	#[Editable(['add', 'update'])]
	#[Filterable]
	public int $iblockSectionId;

	/** Y|N */
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $active;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $code;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public string $xmlId;

	#[Editable(['add', 'update'])]
	#[Sortable]
	public int $sort;

	#[Editable(['add', 'update'])]
	public string $description;

	/** text|html */
	#[Editable(['add', 'update'])]
	public string $descriptionType;
}
