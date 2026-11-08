<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\ExtraTable;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Read-only: extras have no write surface in REST.
 */
#[OrmEntity(ExtraTable::class)]
class ExtraDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	// Length matches the ORM LengthValidator on NAME; declared here to reach the field schema.
	#[Length(max: 50)]
	#[Filterable, Sortable]
	public ?string $name;

	#[Filterable, Sortable]
	public ?float $percentage;
}
