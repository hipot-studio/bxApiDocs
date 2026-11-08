<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\AddressTypeDtoMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('address type')]
#[MappedBy(AddressTypeDtoMapper::class)]
final class AddressTypeDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	#[Filterable]
	#[Sortable]
	public string $name;

	#[Filterable]
	#[Sortable]
	public string $title;
}
