<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\Custom;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

final class EnumDto extends Dto
{
	#[Filterable, Sortable]
	public int $id;

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
	#[Filterable, Sortable]
	public int $sort;

	#[Editable]
	public ?string $xmlId;
}
