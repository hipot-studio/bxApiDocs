<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Sale\Infrastructure\Rest\Dto\OrderDto;

/**
 * Maps a raw order aggregate to OrderDto.
 *
 * Only the flat fields of the order live here. Every nested part carries its own #[MappedBy]
 * mapper, and the core walks them through autoMapRelations() of the base class: the recursion,
 * the per-level select and the nullable collections are the ones of the platform.
 */
final class OrderMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return OrderDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'accountNumber' => ['ACCOUNT_NUMBER', self::STRING],
			'lid' => ['LID', self::STRING],
			'personTypeId' => ['PERSON_TYPE_ID', self::INT],
			'personTypeXmlId' => ['PERSON_TYPE_XML_ID', self::STRING],
			'userId' => ['USER_ID', self::INT],
			'currency' => ['CURRENCY', self::STRING],
			'price' => ['PRICE', self::FLOAT],
			'discountValue' => ['DISCOUNT_VALUE', self::FLOAT],
			'taxValue' => ['TAX_VALUE', self::FLOAT],
			'sumPaid' => ['SUM_PAID', self::FLOAT],
			'statusId' => ['STATUS_ID', self::STRING],
			'statusXmlId' => ['STATUS_XML_ID', self::STRING],
			'dateStatus' => ['DATE_STATUS', self::DATE],
			'empStatusId' => ['EMP_STATUS_ID', self::INT],
			'payed' => ['PAYED', self::BOOL],
			'deducted' => ['DEDUCTED', self::BOOL],
			'canceled' => ['CANCELED', self::BOOL],
			'dateCanceled' => ['DATE_CANCELED', self::DATE],
			'empCanceledId' => ['EMP_CANCELED_ID', self::INT],
			'reasonCanceled' => ['REASON_CANCELED', self::STRING],
			'marked' => ['MARKED', self::BOOL],
			'dateMarked' => ['DATE_MARKED', self::DATE],
			'empMarkedId' => ['EMP_MARKED_ID', self::INT],
			'reasonMarked' => ['REASON_MARKED', self::STRING],
			'dateInsert' => ['DATE_INSERT', self::DATE],
			'dateUpdate' => ['DATE_UPDATE', self::DATE],
			'userDescription' => ['USER_DESCRIPTION', self::STRING],
			'additionalInfo' => ['ADDITIONAL_INFO', self::STRING],
			'comments' => ['COMMENTS', self::STRING],
			'orderTopic' => ['ORDER_TOPIC', self::STRING],
			'responsibleId' => ['RESPONSIBLE_ID', self::INT],
			'companyId' => ['COMPANY_ID', self::INT],
			'affiliateId' => ['AFFILIATE_ID', self::INT],
			'recurringId' => ['RECURRING_ID', self::INT],
			'lockedBy' => ['LOCKED_BY', self::INT],
			'dateLock' => ['DATE_LOCK', self::DATE],
			'recountFlag' => ['RECOUNT_FLAG', self::BOOL],
			'version' => ['VERSION', self::INT],
			'xmlId' => ['XML_ID', self::STRING],
			'id1c' => ['ID_1C', self::STRING],
			'version1c' => ['VERSION_1C', self::STRING],
			'updated1c' => ['UPDATED_1C', self::BOOL],
			'externalOrder' => ['EXTERNAL_ORDER', self::BOOL],
		];
	}

	protected function getComputedFieldMap(): array
	{
		return [
			'vatRate' => self::FLOAT,
			'vatSum' => self::FLOAT,
			'useVat' => self::BOOL,
			'shipped' => self::BOOL,
		];
	}
}
