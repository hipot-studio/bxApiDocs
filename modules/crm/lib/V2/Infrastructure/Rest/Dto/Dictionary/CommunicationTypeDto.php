<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\CommunicationTypeDtoMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The id, name and nameShort fields repeat MultifieldValueTypeDto - one value type has one shape
 * across the API. They are declared here instead of being inherited because a dictionary entry is
 * filterable and sortable, and the published type must not gain those abilities.
 */
#[Title('communication value type')]
#[MappedBy(CommunicationTypeDtoMapper::class)]
final class CommunicationTypeDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public string $id;

	#[Filterable]
	#[Sortable]
	public ?string $name;

	#[Filterable]
	#[Sortable]
	public ?string $nameShort;

	#[Filterable]
	#[Sortable]
	public bool $isDeprecated;
}
