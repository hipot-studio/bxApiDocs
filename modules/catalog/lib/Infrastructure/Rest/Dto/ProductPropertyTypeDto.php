<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Product property type enumeration record for catalog.product.property.type.list.
 * The enumeration carries no label, hence no `name`.
 * Not filterable / sortable: the list is static and returned as a whole.
 */
class ProductPropertyTypeDto extends Dto
{
	public ?string $id;

	public ?string $propertyType;

	public ?string $userType;
}
