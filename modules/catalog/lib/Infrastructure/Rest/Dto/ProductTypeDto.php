<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Product type enumeration record for catalog.product.type.list.
 * Not filterable / sortable: the list is static and returned as a whole.
 */
class ProductTypeDto extends Dto
{
	public ?int $id;

	public ?string $name;
}
