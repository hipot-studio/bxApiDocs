<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;

/**
 * Items of the shipment are mapped by their own mapper.
 */
final class ShipmentMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return ShipmentDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'accountNumber' => ['ACCOUNT_NUMBER', self::STRING],
			'deliveryId' => ['DELIVERY_ID', self::INT],
			'deliveryName' => ['DELIVERY_NAME', self::STRING],
			'deliveryXmlId' => ['DELIVERY_XML_ID', self::STRING],
			'statusId' => ['STATUS_ID', self::STRING],
			'statusXmlId' => ['STATUS_XML_ID', self::STRING],
			'basePriceDelivery' => ['BASE_PRICE_DELIVERY', self::FLOAT],
			'priceDelivery' => ['PRICE_DELIVERY', self::FLOAT],
			'discountPrice' => ['DISCOUNT_PRICE', self::FLOAT],
			'customPriceDelivery' => ['CUSTOM_PRICE_DELIVERY', self::BOOL],
			'currency' => ['CURRENCY', self::STRING],
			'allowDelivery' => ['ALLOW_DELIVERY', self::BOOL],
			'dateAllowDelivery' => ['DATE_ALLOW_DELIVERY', self::DATE],
			'empAllowDeliveryId' => ['EMP_ALLOW_DELIVERY_ID', self::INT],
			'deducted' => ['DEDUCTED', self::BOOL],
			'dateDeducted' => ['DATE_DEDUCTED', self::DATE],
			'empDeductedId' => ['EMP_DEDUCTED_ID', self::INT],
			'reasonUndoDeducted' => ['REASON_UNDO_DEDUCTED', self::STRING],
			'deliveryDocNum' => ['DELIVERY_DOC_NUM', self::STRING],
			'deliveryDocDate' => ['DELIVERY_DOC_DATE', self::DATE],
			'trackingNumber' => ['TRACKING_NUMBER', self::STRING],
			'trackingStatus' => ['TRACKING_STATUS', self::STRING],
			'trackingLastCheck' => ['TRACKING_LAST_CHECK', self::DATE],
			'trackingDescription' => ['TRACKING_DESCRIPTION', self::STRING],
			'responsibleId' => ['RESPONSIBLE_ID', self::INT],
			'empResponsibleId' => ['EMP_RESPONSIBLE_ID', self::INT],
			'dateResponsibleId' => ['DATE_RESPONSIBLE_ID', self::DATE],
			'companyId' => ['COMPANY_ID', self::INT],
			'comments' => ['COMMENTS', self::STRING],
			'marked' => ['MARKED', self::BOOL],
			'dateMarked' => ['DATE_MARKED', self::DATE],
			'empMarkedId' => ['EMP_MARKED_ID', self::INT],
			'reasonMarked' => ['REASON_MARKED', self::STRING],
			'canceled' => ['CANCELED', self::BOOL],
			'dateCanceled' => ['DATE_CANCELED', self::DATE],
			'empCanceledId' => ['EMP_CANCELED_ID', self::INT],
			'dateInsert' => ['DATE_INSERT', self::DATE],
			'xmlId' => ['XML_ID', self::STRING],
			'id1c' => ['ID_1C', self::STRING],
			'version1c' => ['VERSION_1C', self::STRING],
			'updated1c' => ['UPDATED_1C', self::BOOL],
			'externalDelivery' => ['EXTERNAL_DELIVERY', self::BOOL],
		];
	}
}
