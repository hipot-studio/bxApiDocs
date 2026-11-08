<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\StageSemanticDtoMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM stage outcome')]
#[MappedBy(StageSemanticDtoMapper::class)]
class StageSemanticDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public string $id;

	#[Filterable]
	#[Sortable]
	public string $name;
}
