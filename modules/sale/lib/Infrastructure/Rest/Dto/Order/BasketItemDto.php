<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\BasketItemMapper;
use Bitrix\Sale\Infrastructure\Rest\Service\FieldAccessPolicy;

/**
 * Basket item of an order.
 *
 * The list of the fields is closed by the rule of the resource ({@see FieldAccessPolicy}): the
 * schema describes the data the position serves, so it is opened to whoever may read that data.
 */
#[MappedBy(BasketItemMapper::class)]
#[FieldAccess(FieldAccessPolicy::class)]
class BasketItemDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_ID', null, null, __FILE__))]
	public ?int $id;

	// The order owning the position: read only, and the single condition the list of positions
	// takes. Writable it would turn into a requirement of the basket nested in `sale.order.add`,
	// where the order is not addressed at all.
	#[Filterable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_ORDER_ID', null, null, __FILE__))]
	public ?int $orderId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PRODUCT_ID', null, null, __FILE__))]
	public ?int $productId;

	#[Editable(['add', 'update'])]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_NAME', null, null, __FILE__))]
	public ?string $name;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_QUANTITY', null, null, __FILE__))]
	public ?float $quantity;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PRICE', null, null, __FILE__))]
	public ?float $price;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_BASE_PRICE', null, null, __FILE__))]
	public ?float $basePrice;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_DISCOUNT_PRICE', null, null, __FILE__))]
	public ?float $discountPrice;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CURRENCY', null, null, __FILE__))]
	public ?string $currency;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CUSTOM_PRICE', null, null, __FILE__))]
	public ?bool $customPrice;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_VAT_RATE', null, null, __FILE__))]
	public ?float $vatRate;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_VAT_INCLUDED', null, null, __FILE__))]
	public ?bool $vatIncluded;

	// The five amounts below have no column of their own: the domain counts them from the price,
	// the quantity and the rate of VAT of the position. They are read only for that reason, and
	// their titles carry the meaning their names do not: a total of the position is not a price
	// of a unit.
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PRICE_WITH_VAT', null, null, __FILE__))]
	public ?float $priceWithVat;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_BASE_PRICE_WITH_VAT', null, null, __FILE__))]
	public ?float $basePriceWithVat;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_VAT_AMOUNT', null, null, __FILE__))]
	public ?float $vatAmount;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_FINAL_PRICE', null, null, __FILE__))]
	public ?float $finalPrice;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_INITIAL_PRICE', null, null, __FILE__))]
	public ?float $initialPrice;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_WEIGHT', null, null, __FILE__))]
	public ?float $weight;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_DIMENSIONS', null, null, __FILE__))]
	public ?string $dimensions;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_MEASURE_CODE', null, null, __FILE__))]
	public ?string $measureCode;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_MEASURE_NAME', null, null, __FILE__))]
	public ?string $measureName;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CAN_BUY', null, null, __FILE__))]
	public ?bool $canBuy;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_TYPE', null, null, __FILE__))]
	public ?int $type;

	#[Editable(['add', 'update'])]
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_SORT', null, null, __FILE__))]
	public ?int $sort;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;

	#[Editable(['add', 'update'])]
	#[FixedAtCreation]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_XML_ID', null, null, __FILE__))]
	public ?string $catalogXmlId;

	#[Editable(['add', 'update'])]
	#[FixedAtCreation]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PRODUCT_XML_ID', null, null, __FILE__))]
	public ?string $productXmlId;

	// Goods of the position carry a barcode each rather than one for the whole position.
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_BARCODE_MULTI', null, null, __FILE__))]
	public ?bool $barcodeMulti;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_DELAY', null, null, __FILE__))]
	public ?bool $delay;

	// A free basket line with no product module, provider class or callback; unrelated to `customPrice`.
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_WITHOUT_PRODUCT_PROVIDER', null, null, __FILE__))]
	public ?bool $withoutProductProvider;

	// What the domain counts over the reserves of the position. Filling it costs a query of its
	// own, so it arrives only when the select names it; see OrderService::countBasketItemComputed().
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVED_QUANTITY', null, null, __FILE__))]
	public ?float $reservedQuantity;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_DATE_INSERT', null, null, __FILE__))]
	public ?DateTime $dateInsert;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_DATE_UPDATE', null, null, __FILE__))]
	public ?DateTime $dateUpdate;

	#[Editable(['add', 'update'])]
	#[ElementType(BasketItemPropertyDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_PROPERTIES', null, null, __FILE__))]
	public ?DtoCollection $properties;

	#[ElementType(BasketItemReservationDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_RESERVATIONS', null, null, __FILE__))]
	public ?DtoCollection $reservations;
}
