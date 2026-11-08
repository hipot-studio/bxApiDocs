<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Placeholder DTO for DocumentMode controller #[DtoType].
 * Mode actions return ArrayResponse payloads, not this DTO.
 */
class DocumentModeDto extends Dto
{
	public ?string $status;
}
