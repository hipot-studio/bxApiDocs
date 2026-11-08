<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM entity type')]
final class EntityTypeDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	#[Filterable]
	#[Sortable]
	public string $code;

	#[Filterable]
	#[Sortable]
	public string $title;
}
