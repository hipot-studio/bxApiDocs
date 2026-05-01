<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Field\Custom;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class EnumDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

	#[Filterable]
	#[Required()]
	public string $entityId;

	#[Filterable]
	#[Required(['add'])]
	#[Editable(['add'])]
	public int $fieldId;

	#[Required(['add'])]
	#[Editable]
	public string $value;

	#[Editable]
	public bool $isDefault;

	#[Editable]
	public int $sort;

	#[Editable]
	public string $xmlId;
}