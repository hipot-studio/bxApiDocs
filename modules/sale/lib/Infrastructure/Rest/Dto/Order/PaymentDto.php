<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\PaymentMapper;
use Bitrix\Sale\Payment;

/**
 * Payment of an order.
 */
#[MappedBy(PaymentMapper::class)]
class PaymentDto extends Dto
{
	// An existing element is addressed by its identifier, and only an update has elements to
	// address: an identifier passed to a create would name an element of another order.
	#[Editable(['update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_ID', null, null, __FILE__))]
	public ?int $id;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_ACCOUNT_NUMBER', null, null, __FILE__))]
	public ?string $accountNumber;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_SYSTEM_ID', null, null, __FILE__))]
	public ?int $paySystemId;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_SYSTEM_NAME', null, null, __FILE__))]
	public ?string $paySystemName;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_SYSTEM_XML_ID', null, null, __FILE__))]
	public ?string $paySystemXmlId;

	// Not a flag: the pay system is cash (Y), cashless (N) or acquiring (A), and the exchange tells
	// the last one apart.
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_SYSTEM_IS_CASH', null, null, __FILE__))]
	public ?string $paySystemIsCash;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_SUM', null, null, __FILE__))]
	public ?float $sum;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_CURRENCY', null, null, __FILE__))]
	public ?string $currency;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAID', null, null, __FILE__))]
	public ?bool $paid;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_DATE_PAID', null, null, __FILE__))]
	public ?DateTime $datePaid;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_EMP_PAID_ID', null, null, __FILE__))]
	public ?int $empPaidId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_DATE_BILL', null, null, __FILE__))]
	public ?DateTime $dateBill;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_DATE_PAY_BEFORE', null, null, __FILE__))]
	public ?Date $datePayBefore;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_STATUS', null, null, __FILE__))]
	public ?bool $psStatus;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_STATUS_CODE', null, null, __FILE__))]
	public ?string $psStatusCode;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_STATUS_DESCRIPTION', null, null, __FILE__))]
	public ?string $psStatusDescription;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_STATUS_MESSAGE', null, null, __FILE__))]
	public ?string $psStatusMessage;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_SUM', null, null, __FILE__))]
	public ?float $psSum;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_CURRENCY', null, null, __FILE__))]
	public ?string $psCurrency;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_RESPONSE_DATE', null, null, __FILE__))]
	public ?DateTime $psResponseDate;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PS_INVOICE_ID', null, null, __FILE__))]
	public ?string $psInvoiceId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_VOUCHER_NUM', null, null, __FILE__))]
	public ?string $payVoucherNum;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_VOUCHER_DATE', null, null, __FILE__))]
	public ?Date $payVoucherDate;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PRICE_COD', null, null, __FILE__))]
	public ?float $priceCod;

	// Not a flag: no return (N), a return to the inner account (Y) or through the pay system (P). The
	// domain reads every other value as a return, so it is refused here.
	#[Editable(['add', 'update'])]
	#[InArray([Payment::RETURN_NONE, Payment::RETURN_INNER, Payment::RETURN_PS], strict: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_IS_RETURN', null, null, __FILE__))]
	public ?string $isReturn;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_RETURN_NUM', null, null, __FILE__))]
	public ?string $payReturnNum;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_RETURN_DATE', null, null, __FILE__))]
	public ?Date $payReturnDate;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_PAY_RETURN_COMMENT', null, null, __FILE__))]
	public ?string $payReturnComment;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_EMP_RETURN_ID', null, null, __FILE__))]
	public ?int $empReturnId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?int $responsibleId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_EMP_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?int $empResponsibleId;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_DATE_RESPONSIBLE_ID', null, null, __FILE__))]
	public ?DateTime $dateResponsibleId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_COMPANY_ID', null, null, __FILE__))]
	public ?int $companyId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_COMMENTS', null, null, __FILE__))]
	public ?string $comments;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_MARKED', null, null, __FILE__))]
	public ?bool $marked;

	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_DATE_MARKED', null, null, __FILE__))]
	public ?DateTime $dateMarked;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_EMP_MARKED_ID', null, null, __FILE__))]
	public ?int $empMarkedId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_REASON_MARKED', null, null, __FILE__))]
	public ?string $reasonMarked;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_XML_ID', null, null, __FILE__))]
	public ?string $xmlId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_ID_1C', null, null, __FILE__))]
	public ?string $id1c;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_VERSION_1C', null, null, __FILE__))]
	public ?string $version1c;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_UPDATED_1C', null, null, __FILE__))]
	public ?bool $updated1c;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_PAYMENT_EXTERNAL_PAYMENT', null, null, __FILE__))]
	public ?string $externalPayment;
}
