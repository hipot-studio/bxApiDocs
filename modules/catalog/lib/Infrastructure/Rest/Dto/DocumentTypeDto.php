<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Store document type enumeration record for catalog.document.type.list.
 * Not filterable / sortable: the list is static and returned as a whole.
 */
class DocumentTypeDto extends Dto
{
	public ?string $id;

	public ?string $name;
}
