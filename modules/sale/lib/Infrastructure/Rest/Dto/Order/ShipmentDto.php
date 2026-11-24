<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\ShipmentMapper;

/**
 * Shipment of an order. The system shipment is never exposed, so it has no flag here.
 */
#[MappedBy(ShipmentMapper::class)]
class ShipmentDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ID', null, null, __FILE__))]
	public ?int $id;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ACCOUNT_NUMBER', null, null, __FILE__))]
	public ?string $accountNumber;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DELIVERY_ID', null, null, __FILE__))]
	public ?int $deliveryId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DELIVERY_NAME', null, null, __FILE__))]
	public ?string $deliveryName;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DELIVERY_XML_ID', null, null, __FILE__))]
	public ?string $deliveryXmlId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_STATUS_ID', null, null, __FILE__))]
	public ?string $statusId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_STATUS_XML_ID', null, null, __FILE__))]
	public ?string $statusXmlId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_BASE_PRICE_DELIVERY', null, null, __FILE__))]
	public ?float $basePriceDelivery;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_PRICE_DELIVERY', null, null, __FILE__))]
	public ?float $priceDelivery;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DISCOUNT_PRICE', null, null, __FILE__))]
	public ?float $discountPrice;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_CUSTOM_PRICE_DELIVERY', null, null, __FILE__))]
	public ?bool $customPriceDelivery;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_CURRENCY', null, null, __FILE__))]
	public ?string $currency;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ALLOW_DELIVERY', null, null, __FILE__))]
	public ?bool $allowDelivery;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_ALLOW_DELIVERY', null, null, __FILE__))]
	public ?DateTime $dateAllowDelivery;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EMP_ALLOW_DELIVERY_ID', null, null, __FILE__))]
	public ?int $empAllowDeliveryId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DEDUCTED', null, null, __FILE__))]
	public ?bool $deducted;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_DEDUCTED', null, null, __FILE__))]
	public ?DateTime $dateDeducted;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EMP_DEDUCTED_ID', null, null, __FILE__))]
	public ?int $empDeductedId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_REASON_UNDO_DEDUCTED', null, null, __FILE__))]
	public ?string $reasonUndoDeducted;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DELIVERY_DOC_NUM', null, null, __FILE__))]
	public ?string $deliveryDocNum;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DELIVERY_DOC_DATE', null, null, __FILE__))]
	public ?DateTime $deliveryDocDate;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_TRACKING_NUMBER', null, null, __FILE__))]
	public ?string $trackingNumber;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_TRACKING_STATUS', null, null, __FILE__))]
	public ?string $trackingStatus;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_TRACKING_LAST_CHECK', null, null, __FILE__))]
	public ?DateTime $trackingLastCheck;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_TRACKING_DESCRIPTION', null, null, __FILE__))]
	public ?string $trackingDescription;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?int $responsibleId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EMP_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?int $empResponsibleId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?DateTime $dateResponsibleId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_COMPANY_ID', null, null, __FILE__))]
	public ?int $companyId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_COMMENTS', null, null, __FILE__))]
	public ?string $comments;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_MARKED', null, null, __FILE__))]
	public ?bool $marked;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_MARKED', null, null, __FILE__))]
	public ?DateTime $dateMarked;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EMP_MARKED_ID', null, null, __FILE__))]
	public ?int $empMarkedId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_REASON_MARKED', null, null, __FILE__))]
	public ?string $reasonMarked;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_CANCELED', null, null, __FILE__))]
	public ?bool $canceled;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_CANCELED', null, null, __FILE__))]
	public ?DateTime $dateCanceled;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EMP_CANCELED_ID', null, null, __FILE__))]
	public ?int $empCanceledId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_DATE_INSERT', null, null, __FILE__))]
	public ?DateTime $dateInsert;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_ID_1C', null, null, __FILE__))]
	public ?string $id1c;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_VERSION_1C', null, null, __FILE__))]
	public ?string $version1c;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_UPDATED_1C', null, null, __FILE__))]
	public ?bool $updated1c;

	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_EXTERNAL_DELIVERY', null, null, __FILE__))]
	public ?bool $externalDelivery;

	#[Editable(['add', 'update'])]
	#[ElementType(ShipmentItemDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_SHIPMENT_SHIPMENT_ITEMS', null, null, __FILE__))]
	public ?DtoCollection $shipmentItems;
}
