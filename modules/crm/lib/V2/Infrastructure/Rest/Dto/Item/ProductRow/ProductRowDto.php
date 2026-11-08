<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a product row: an explicit list of properties rather than a projection of
 * `b_crm_product_row`. The stored columns grow while the portal runs - every new smart process adds a
 * link of its own - so a projection would widen the published contract silently.
 *
 * Not bound to an ORM entity: {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowDtoMapper}
 * converts in both directions. A product row has no user fields, so the `UF_*` machinery is not
 * involved either.
 *
 * ### Two decisions here that are load-bearing
 *
 * **No default values.** A property left without a default stays uninitialized until the client sends
 * it, and that is what tells "not sent" apart from "sent as null" on the write side. A default would
 * make a field always present and turn every partial write into a full one.
 *
 * **`taxName` is deliberately not here.** The stored row carries a name of the tax next to its rate, and
 * the legacy contract lets a client write it; this group does not publish it. A published field can never
 * be removed, and the name of a tax has no meaning of its own next to {@see $taxRate} - it is a label the
 * legacy form filled in. Adding it later is a compatible extension, so the omission costs nothing and
 * publishing it prematurely would cost the contract. The field is written and read on the paths that
 * already had it, see {@see \Bitrix\Crm\V2\Public\Entity\Item\ProductRow::getTaxName()}.
 *
 * **The two existence checks are not attributes.** The owner id is checked within the entity type of
 * the route, which is not a property of this class - one DTO serves every type that works with
 * products. And a validation attribute is shared between the DTO instances of a single request (the
 * field cache hands out the same rule objects), which a rule caching its valid values must never be:
 * the second row of a `replace` would be judged by the values of the first. Both checks are therefore
 * made per request, by the mapper.
 */
#[Title('CRM product row')]
final class ProductRowDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public ?int $id;

	#[Editable(['add'])]
	#[Required(['add'])]
	#[Filterable]
	#[PositiveNumber]
	public int $ownerId;

	public int $ownerTypeId;

	#[Editable]
	#[Filterable]
	public ?int $productId;

	#[Editable]
	public ?string $productName;

	#[Editable]
	public float $price;

	#[Editable]
	public float $quantity;

	/** @see \Bitrix\Crm\Discount */
	#[Editable]
	public ?int $discountTypeId;

	#[Editable]
	public ?float $discountRate;

	#[Editable]
	public float $discountSum;

	/** An empty value means no tax at all, which is why this one is nullable by contract. */
	#[Editable]
	public ?float $taxRate;

	#[Editable]
	public ?bool $taxIncluded;

	/** Measure classifier code, not a reference to a measure record. */
	#[Editable]
	public ?int $measureCode;

	#[Editable]
	public ?string $measureName;

	#[Editable]
	#[Sortable]
	public int $sort;

	/** @see \Bitrix\Crm\ProductType */
	public ?int $productTypeId;
}
