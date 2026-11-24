<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;

/**
 * Properties and reservations of the item are mapped by their own mappers.
 */
final class BasketItemMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return BasketItemDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'orderId' => ['ORDER_ID', self::INT],
			'productId' => ['PRODUCT_ID', self::INT],
			'name' => ['NAME', self::STRING],
			'quantity' => ['QUANTITY', self::FLOAT],
			'price' => ['PRICE', self::FLOAT],
			'basePrice' => ['BASE_PRICE', self::FLOAT],
			'discountPrice' => ['DISCOUNT_PRICE', self::FLOAT],
			'currency' => ['CURRENCY', self::STRING],
			'customPrice' => ['CUSTOM_PRICE', self::BOOL],
			'vatRate' => ['VAT_RATE', self::FLOAT],
			'vatIncluded' => ['VAT_INCLUDED', self::BOOL],
			'weight' => ['WEIGHT', self::FLOAT],
			'dimensions' => ['DIMENSIONS', self::STRING],
			'measureCode' => ['MEASURE_CODE', self::STRING],
			'measureName' => ['MEASURE_NAME', self::STRING],
			'canBuy' => ['CAN_BUY', self::BOOL],
			'type' => ['TYPE', self::INT],
			'sort' => ['SORT', self::INT],
			'xmlId' => ['XML_ID', self::STRING],
			'catalogXmlId' => ['CATALOG_XML_ID', self::STRING],
			'productXmlId' => ['PRODUCT_XML_ID', self::STRING],
			'barcodeMulti' => ['BARCODE_MULTI', self::BOOL],
			'delay' => ['DELAY', self::BOOL],
			'dateInsert' => ['DATE_INSERT', self::DATE],
			'dateUpdate' => ['DATE_UPDATE', self::DATE],
		];
	}

	/**
	 * The five amounts the domain counts from the price, the quantity and the rate of VAT of the
	 * position, the flag of a free line and the reserve. The service asks the position for them;
	 * here they are only cast to the transport contract, as every other value of the type.
	 */
	protected function getComputedFieldMap(): array
	{
		return [
			'priceWithVat' => self::FLOAT,
			'basePriceWithVat' => self::FLOAT,
			'vatAmount' => self::FLOAT,
			'finalPrice' => self::FLOAT,
			'initialPrice' => self::FLOAT,
			'withoutProductProvider' => self::BOOL,
			'reservedQuantity' => self::FLOAT,
		];
	}
}
