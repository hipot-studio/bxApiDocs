<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping\ProductImageMapper;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

/**
 * A product image. No #[OrmEntity]: the entity is a projection of a file onto a product,
 * assembled by {@see ProductImageMapper} from element fields and MORE_PHOTO property values.
 */
#[MappedBy(ProductImageMapper::class)]
class ProductImageDto extends Dto
{
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[Filterable]
	public int $productId;

	#[Required(['add'])]
	#[Editable(['add'])]
	public ProductImageType $type;

	public ?string $name;

	public ?DateTime $createTime;

	public ?string $detailUrl;

	public ?string $downloadUrl;

	#[Required(['add'])]
	#[Editable(['add'])]
	public FileDto $file;
}
