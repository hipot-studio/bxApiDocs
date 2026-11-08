<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\ProductPropertyMapper;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Product property create payload / response for catalog.product.property.*.
 */
#[MappedBy(ProductPropertyMapper::class)]
class ProductPropertyDto extends Dto
{
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	public ?int $iblockId;

	#[Required(['add'])]
	#[Editable(['add'])]
	public ?string $name;

	#[Editable(['add'])]
	public ?int $sort;

	#[Editable(['add'])]
	public ?string $code;

	/** Y/N */
	#[Editable(['add'])]
	public ?string $multiple;

	/** Y/N */
	#[Editable(['add'])]
	public ?string $isRequired;

	public ?string $controlId;
}
