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
use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order\ClientMapper;

/**
 * CRM client bound to an order. Present only when the crm module is installed.
 */
#[MappedBy(ClientMapper::class)]
class ClientDto extends Dto
{
	// Read-only, unlike the identifier of the five other addressable elements, and the reason is in
	// the domain rather than in this contract. `Crm\Order\Builder\OrderBuilderRest::buildClients()`
	// reads `ID` to find the binding and then hands the very same array to `setFields()`, with no
	// settable-field filter of the kind `sale` applies to payments and shipments; `ID` is not among
	// the available fields of `Crm\Order\ContactCompanyEntity`, so `Entity::setField()` throws
	// ArgumentOutOfRangeException. Accepting the identifier here would therefore turn a legitimate
	// round trip into a 500. Making a client addressable needs that filter in `crm` first, and
	// filling an existing binding from the entity behind it in {@see OrderFormBuilder} second.
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_ID', null, null, __FILE__))]
	public ?int $id;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_ENTITY_TYPE_ID', null, null, __FILE__))]
	public ?int $entityTypeId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[FixedAtCreation(ignoredOnChange: true)]
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_ENTITY_ID', null, null, __FILE__))]
	public ?int $entityId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_ROLE_ID', null, null, __FILE__))]
	public ?int $roleId;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_IS_PRIMARY', null, null, __FILE__))]
	public ?bool $isPrimary;

	#[Editable(['add', 'update'])]
	#[Title(new LocalizableMessage('SALE_REST_V3_CLIENT_SORT', null, null, __FILE__))]
	public ?int $sort;
}
