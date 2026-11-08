<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\GroupAccessTable;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(GroupAccessTable::class)]
class PriceTypeGroupDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $catalogGroupId;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public int $groupId;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable, Sortable]
	public string $access;
}
