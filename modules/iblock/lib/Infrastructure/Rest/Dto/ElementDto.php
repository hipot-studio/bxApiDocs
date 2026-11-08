<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Dto;

use Bitrix\Iblock\Infrastructure\Rest\Dto\Mapping\ElementMapper;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(ElementMapper::class)]
class ElementDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable]
	#[Min(1)]
	#[NotEmpty]
	public int $iblockId;

	#[Filterable, Sortable]
	public ?string $name;

	#[Filterable, Sortable]
	public ?int $iblockSectionId;

	/**
	 * Property bags keyed by property code/name; values limited to id/value/description.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	public ?array $properties;
}
