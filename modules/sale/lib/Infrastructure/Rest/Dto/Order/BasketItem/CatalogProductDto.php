<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItem;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\FieldAccess;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\BasketItemMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Service\FieldAccessPolicy;

/**
 * Basket position of an order holding a product of the catalog.
 *
 * The position is the same entity as {@see BasketItemDto} and is read, listed and removed by the
 * same resource: what a catalog product has of its own is the set of fields its client may write.
 * The price of the goods, their name and their characteristics are counted by the provider of the
 * catalog, so the client does not name them - V1 refused the very same fields as read only
 * (`Sale\Rest\Entity\BasketItem::getFieldsInfoCatalogProduct()`).
 *
 * Only those fields are declared here. Everything else - the composition of the fields, their
 * titles, the requirements at creation and the mapping on the domain - is the one of the position,
 * and stays that way by inheritance: the mapping is the very same {@see BasketItemMapper}, because
 * a second field map would drift from the first at the first field added to either.
 *
 * The price stays writable, as in V1: it lives in the common part of the field schema
 * (`getFieldsInfoItem()`), which the catalog part is added to by union and therefore never
 * overrides. A position whose price the client names is marked as priced by hand by the domain.
 *
 * A class attribute is not inherited by reflection, so the mapping and the access rule of the
 * field methods are declared again rather than taken from the parent.
 */
#[MappedBy(BasketItemMapper::class)]
#[FieldAccess(FieldAccessPolicy::class)]
class CatalogProductDto extends BasketItemDto
{
	#[Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_NAME', null, null, __FILE__))]
	public ?string $name;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_BASE_PRICE', null, null, __FILE__))]
	public ?float $basePrice;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_DISCOUNT_PRICE', null, null, __FILE__))]
	public ?float $discountPrice;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_VAT_RATE', null, null, __FILE__))]
	public ?float $vatRate;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_VAT_INCLUDED', null, null, __FILE__))]
	public ?bool $vatIncluded;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_WEIGHT', null, null, __FILE__))]
	public ?float $weight;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_DIMENSIONS', null, null, __FILE__))]
	public ?string $dimensions;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_MEASURE_CODE', null, null, __FILE__))]
	public ?string $measureCode;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_MEASURE_NAME', null, null, __FILE__))]
	public ?string $measureName;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_CAN_BUY', null, null, __FILE__))]
	public ?bool $canBuy;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_TYPE', null, null, __FILE__))]
	public ?int $type;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_CATALOG_XML_ID', null, null, __FILE__))]
	public ?string $catalogXmlId;

	#[Title(new LocalizableMessage('SALE_REST_V3_BASKET_ITEM_CATALOG_PRODUCT_PRODUCT_XML_ID', null, null, __FILE__))]
	public ?string $productXmlId;
}
