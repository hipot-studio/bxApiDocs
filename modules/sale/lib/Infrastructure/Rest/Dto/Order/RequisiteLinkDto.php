<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Order;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Sale\Infrastructure\Rest\Dto\Attribute\FixedAtCreation;
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\RequisiteLinkMapper;

/**
 * CRM requisite of an order. Present only when the crm module is installed.
 */
#[MappedBy(RequisiteLinkMapper::class)]
class RequisiteLinkDto extends Dto
{
	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_ENTITY_TYPE_ID', null, null, __FILE__))]
	public ?int $entityTypeId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_ENTITY_ID', null, null, __FILE__))]
	public ?int $entityId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_REQUISITE_ID', null, null, __FILE__))]
	public ?int $requisiteId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_BANK_DETAIL_ID', null, null, __FILE__))]
	public ?int $bankDetailId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_MC_REQUISITE_ID', null, null, __FILE__))]
	public ?int $mcRequisiteId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_REQUISITE_LINK_MC_BANK_DETAIL_ID', null, null, __FILE__))]
	public ?int $mcBankDetailId;
}
