<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Placeholder DTO for AgentContractEntity controller #[DtoType].
 * Action is delete (single id or id-filter).
 */
class AgentContractEntityDto extends Dto
{
	#[Filterable]
	public ?int $id;
}
