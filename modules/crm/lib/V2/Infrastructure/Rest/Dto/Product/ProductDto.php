<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Product;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The external contract of a card of the CRM product catalog - the catalogue entry, not its use inside
 * an Item. The fields of a product row (owner, quantity, discount) are absent because a card has none
 * of them.
 *
 * An explicit list of properties rather than a projection of the storage: a card is stitched together
 * from an element of the CRM catalog iblock and what the catalog module keeps about it, and a projection
 * of either would publish their internals. Deliberately left out and not by omission: the announce and detail
 * pictures, the way the description is stored (`DESCRIPTION_TYPE` - a storage detail, not a property of
 * a product), and the iblock properties. A published field can never be removed, so the list starts
 * narrow; widening it later is compatible, narrowing it is not.
 *
 * No property carries {@see \Bitrix\Rest\V3\Attribute\Editable}: the only method behind this contract
 * reads.
 *
 * `Filterable` and `Sortable` are closed lists and are the same lists
 * {@see \Bitrix\Crm\V2\Internal\Repository\Product\ProductRepository} can answer for - the property
 * names of this class are the field names of {@see \Bitrix\Crm\V2\Internal\Entity\Product\ProductCard}
 * one to one, so nothing is renamed on the way in or out. Any ordering is settled by the primary key.
 *
 * `vatId` publishes a reference to a VAT rate while v3 offers no way to list the allowed rates yet.
 * That is a known departure from the regulation, the same one `measureCode` of a product row carries.
 */
#[Title('CRM catalog product')]
final class ProductDto extends Dto
{
	/** Also the identifier of the iblock element behind the card: the two are the same number. */
	#[Filterable]
	#[Sortable]
	public int $id;

	/**
	 * The catalog the card belongs to. Read-only here, as it is in legacy.
	 *
	 * A card belongs to one catalog, so filtering by this field chooses which catalog is read rather than
	 * narrows a read of them all: the condition is a plain equality with a catalog of the CRM, and a filter
	 * that names none reads the default one.
	 */
	#[Filterable]
	public int $catalogId;

	#[Filterable]
	#[Sortable]
	public string $name;

	#[Filterable]
	public bool $active;

	#[Filterable]
	public ?int $sectionId;

	#[Filterable]
	public ?string $xmlId;

	#[Sortable]
	public int $sort;

	public ?string $code;

	public ?string $description;

	/** Base price of the card, kept by the catalog module rather than by the card itself. */
	public ?float $price;

	public ?string $currencyId;

	/** A VAT rate of the catalog module; empty when the card carries no rate. */
	public ?int $vatId;

	public bool $vatIncluded;

	/** Measure classifier code, not a reference to a measure record. */
	public ?int $measureCode;

	/**
	 * Nullable because the source is: a card is read field by field, and a date the client did not ask
	 * for is not read at all. The columns behind the two are filled on every card of the catalog, so a
	 * client that asks for a date is answered one - but the published description states what the storage
	 * guarantees rather than what the data happens to look like.
	 *
	 * @see \Bitrix\Crm\V2\Internal\Entity\Product\ProductCard::$createdTime
	 */
	#[Sortable]
	public ?DateTime $createdTime;

	/** @see self::$createdTime for why a date of a card is nullable. */
	#[Sortable]
	public ?DateTime $updatedTime;

	public ?int $createdById;

	public ?int $updatedById;
}
