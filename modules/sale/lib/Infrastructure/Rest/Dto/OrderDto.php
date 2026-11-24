<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\OrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\BasketItemDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ClientDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PaymentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PropertyValueDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\RequisiteLinkDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ShipmentDto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\TradeBindingDto;

/**
 * Order aggregate: sale.order.*.
 *
 * Flat field composition is a one-to-one carry-over of the V1 static schema
 * (Bitrix\Sale\Rest\Entity\Order::getFields()); collections replace its hidden list fields.
 */
#[MappedBy(OrderMapper::class)]
class OrderDto extends Dto
{
	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_ID', null, null, __FILE__))]
	public ?int $id;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_ACCOUNT_NUMBER', null, null, __FILE__))]
	public ?string $accountNumber;

	#[Filterable, Sortable]
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_LID', null, null, __FILE__))]
	public ?string $lid;

	#[Filterable, Sortable]
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PERSON_TYPE_ID', null, null, __FILE__))]
	public ?int $personTypeId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PERSON_TYPE_XML_ID', null, null, __FILE__))]
	public ?string $personTypeXmlId;

	#[Filterable, Sortable]
	#[Editable(['add'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_USER_ID', null, null, __FILE__))]
	public ?int $userId;

	#[Filterable, Sortable]
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_CURRENCY', null, null, __FILE__))]
	public ?string $currency;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PRICE', null, null, __FILE__))]
	public ?float $price;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DISCOUNT_VALUE', null, null, __FILE__))]
	public ?float $discountValue;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_TAX_VALUE', null, null, __FILE__))]
	public ?float $taxValue;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_SUM_PAID', null, null, __FILE__))]
	public ?float $sumPaid;

	// The three VAT values below are computed by the domain and have no column of their own, so
	// they are given out by `get` alone: a listing has nothing to select them by. And by `get`
	// only to a select that names them: the domain counts such a value over the parts of the
	// order, and a read that asked for nothing in particular does not pay for it.
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_VAT_RATE', null, null, __FILE__))]
	public ?float $vatRate;

	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_VAT_SUM', null, null, __FILE__))]
	public ?float $vatSum;

	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_USE_VAT', null, null, __FILE__))]
	public ?bool $useVat;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_STATUS_ID', null, null, __FILE__))]
	public ?string $statusId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_STATUS_XML_ID', null, null, __FILE__))]
	public ?string $statusXmlId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_STATUS', null, null, __FILE__))]
	public ?DateTime $dateStatus;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_EMP_STATUS_ID', null, null, __FILE__))]
	public ?int $empStatusId;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PAYED', null, null, __FILE__))]
	public ?bool $payed;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DEDUCTED', null, null, __FILE__))]
	public ?bool $deducted;

	// `deducted` above is the DEDUCTED column of the order; this one is the answer of the shipment
	// collection, which sale.order.isshipped returns. They are different values, and this one is
	// computed, so it is given out by a `get` that names it, as the VAT values above.
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_SHIPPED', null, null, __FILE__))]
	public ?bool $shipped;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_CANCELED', null, null, __FILE__))]
	public ?bool $canceled;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_CANCELED', null, null, __FILE__))]
	public ?DateTime $dateCanceled;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_EMP_CANCELED_ID', null, null, __FILE__))]
	public ?int $empCanceledId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_REASON_CANCELED', null, null, __FILE__))]
	public ?string $reasonCanceled;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_MARKED', null, null, __FILE__))]
	public ?bool $marked;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_MARKED', null, null, __FILE__))]
	public ?DateTime $dateMarked;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_EMP_MARKED_ID', null, null, __FILE__))]
	public ?int $empMarkedId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_REASON_MARKED', null, null, __FILE__))]
	public ?string $reasonMarked;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_INSERT', null, null, __FILE__))]
	public ?DateTime $dateInsert;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_UPDATE', null, null, __FILE__))]
	public ?DateTime $dateUpdate;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_USER_DESCRIPTION', null, null, __FILE__))]
	public ?string $userDescription;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_ADDITIONAL_INFO', null, null, __FILE__))]
	public ?string $additionalInfo;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_COMMENTS', null, null, __FILE__))]
	public ?string $comments;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_ORDER_TOPIC', null, null, __FILE__))]
	public ?string $orderTopic;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?int $responsibleId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_COMPANY_ID', null, null, __FILE__))]
	public ?int $companyId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_AFFILIATE_ID', null, null, __FILE__))]
	public ?int $affiliateId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_RECURRING_ID', null, null, __FILE__))]
	public ?int $recurringId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_LOCKED_BY', null, null, __FILE__))]
	public ?int $lockedBy;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_DATE_LOCK', null, null, __FILE__))]
	public ?DateTime $dateLock;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_RECOUNT_FLAG', null, null, __FILE__))]
	public ?bool $recountFlag;

	#[Filterable, Sortable]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_VERSION', null, null, __FILE__))]
	public ?int $version;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_ID_1C', null, null, __FILE__))]
	public ?string $id1c;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_VERSION_1C', null, null, __FILE__))]
	public ?string $version1c;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_UPDATED_1C', null, null, __FILE__))]
	public ?bool $updated1c;

	#[Filterable, Sortable]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_EXTERNAL_ORDER', null, null, __FILE__))]
	public ?bool $externalOrder;

	#[Editable(['add', 'update'])]
	#[ElementType(BasketItemDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_BASKET_ITEMS', null, null, __FILE__))]
	public ?DtoCollection $basketItems;

	#[Editable(['add', 'update'])]
	#[ElementType(PropertyValueDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PROPERTY_VALUES', null, null, __FILE__))]
	public ?DtoCollection $propertyValues;

	#[Editable(['add', 'update'])]
	#[ElementType(PaymentDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_PAYMENTS', null, null, __FILE__))]
	public ?DtoCollection $payments;

	#[Editable(['add', 'update'])]
	#[ElementType(ShipmentDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_SHIPMENTS', null, null, __FILE__))]
	public ?DtoCollection $shipments;

	#[Editable(['add', 'update'])]
	#[ElementType(TradeBindingDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_TRADE_BINDINGS', null, null, __FILE__))]
	public ?DtoCollection $tradeBindings;

	#[Editable(['add', 'update'])]
	#[ElementType(ClientDto::class)]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_CLIENTS', null, null, __FILE__))]
	public ?DtoCollection $clients;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_ORDER_REQUISITE_LINK', null, null, __FILE__))]
	public ?RequisiteLinkDto $requisiteLink;
}
