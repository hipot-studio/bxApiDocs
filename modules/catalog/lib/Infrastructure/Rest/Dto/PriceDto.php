<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Service column TMP_ID and the ORM references of PriceTable stay unpublished: parity with V1.
 *
 * An empty `extraId`, `quantityFrom` or `quantityTo` is stored as NULL, but PriceTable declares
 * these columns non-nullable, so the ORM object path renders them as zero on read. A zero sent
 * back is read as empty on write, which keeps an entity read from the API writable as it is.
 */
#[OrmEntity(PriceTable::class)]
class PriceDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	// No Editable: the price cannot be moved to another product after creation.
	#[Required(['add'])]
	#[Filterable, Sortable]
	public ?int $productId;

	// No Editable: the price cannot be moved to another price type after creation.
	#[Required(['add'])]
	#[Filterable, Sortable]
	public ?int $catalogGroupId;

	// A negative value is rejected by Model\Price.
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?float $price;

	// Length matches the ORM validator on CURRENCY; declared here to reach the field schema.
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Length(max: 3)]
	#[Filterable, Sortable]
	public ?string $currency;

	// Model\Price nullifies the extra of a base price type row, on add and on update alike.
	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $extraId;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $quantityFrom;

	#[Editable(['add', 'update'])]
	#[Filterable, Sortable]
	public ?int $quantityTo;

	// Read-only: the price in the base currency is calculated by Model\Price.
	#[Filterable, Sortable]
	public ?float $priceScale;

	// Read-only: always overwritten by the server.
	#[Filterable, Sortable]
	public ?DateTime $timestampX;
}
