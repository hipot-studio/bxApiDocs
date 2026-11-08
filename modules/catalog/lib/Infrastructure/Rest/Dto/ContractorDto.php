<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\ContractorMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Contractor create response / placeholder DTO for #[DtoType].
 */
#[MappedBy(ContractorMapper::class)]
class ContractorDto extends Dto
{
	public ?int $id;

	public ?string $companyName;
}
