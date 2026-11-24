<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\PaymentDto;

final class PaymentMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return PaymentDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'accountNumber' => ['ACCOUNT_NUMBER', self::STRING],
			'paySystemId' => ['PAY_SYSTEM_ID', self::INT],
			'paySystemName' => ['PAY_SYSTEM_NAME', self::STRING],
			'paySystemXmlId' => ['PAY_SYSTEM_XML_ID', self::STRING],
			'paySystemIsCash' => ['PAY_SYSTEM_IS_CASH', self::STRING],
			'sum' => ['SUM', self::FLOAT],
			'currency' => ['CURRENCY', self::STRING],
			'paid' => ['PAID', self::BOOL],
			'datePaid' => ['DATE_PAID', self::DATE],
			'empPaidId' => ['EMP_PAID_ID', self::INT],
			'dateBill' => ['DATE_BILL', self::DATE],
			'datePayBefore' => ['DATE_PAY_BEFORE', self::DATE],
			'psStatus' => ['PS_STATUS', self::BOOL],
			'psStatusCode' => ['PS_STATUS_CODE', self::STRING],
			'psStatusDescription' => ['PS_STATUS_DESCRIPTION', self::STRING],
			'psStatusMessage' => ['PS_STATUS_MESSAGE', self::STRING],
			'psSum' => ['PS_SUM', self::FLOAT],
			'psCurrency' => ['PS_CURRENCY', self::STRING],
			'psResponseDate' => ['PS_RESPONSE_DATE', self::DATE],
			'psInvoiceId' => ['PS_INVOICE_ID', self::STRING],
			'payVoucherNum' => ['PAY_VOUCHER_NUM', self::STRING],
			'payVoucherDate' => ['PAY_VOUCHER_DATE', self::DATE],
			'priceCod' => ['PRICE_COD', self::FLOAT],
			'isReturn' => ['IS_RETURN', self::STRING],
			'payReturnNum' => ['PAY_RETURN_NUM', self::STRING],
			'payReturnDate' => ['PAY_RETURN_DATE', self::DATE],
			'payReturnComment' => ['PAY_RETURN_COMMENT', self::STRING],
			'empReturnId' => ['EMP_RETURN_ID', self::INT],
			'responsibleId' => ['RESPONSIBLE_ID', self::INT],
			'empResponsibleId' => ['EMP_RESPONSIBLE_ID', self::INT],
			'dateResponsibleId' => ['DATE_RESPONSIBLE_ID', self::DATE],
			'companyId' => ['COMPANY_ID', self::INT],
			'comments' => ['COMMENTS', self::STRING],
			'marked' => ['MARKED', self::BOOL],
			'dateMarked' => ['DATE_MARKED', self::DATE],
			'empMarkedId' => ['EMP_MARKED_ID', self::INT],
			'reasonMarked' => ['REASON_MARKED', self::STRING],
			'xmlId' => ['XML_ID', self::STRING],
			'id1c' => ['ID_1C', self::STRING],
			'version1c' => ['VERSION_1C', self::STRING],
			'updated1c' => ['UPDATED_1C', self::BOOL],
			'externalPayment' => ['EXTERNAL_PAYMENT', self::STRING],
		];
	}
}
