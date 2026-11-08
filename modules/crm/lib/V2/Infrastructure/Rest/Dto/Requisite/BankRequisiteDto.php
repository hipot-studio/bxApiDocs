<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\BankRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM bank details')]
#[MappedBy(BankRequisiteDtoMapper::class)]
class BankRequisiteDto extends Dto
{
	private const TEMPLATE_FIELD_DESCRIPTION =
		'This optional property is returned only when the field is available in the current requisite template. '
		. 'An absent property means that the field is unavailable and is different from a null value.'
	;

	#[Filterable]
	#[Sortable]
	#[PositiveNumber]
	public int $id;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[PositiveNumber(groups: ['add'])]
	public int $requisiteId;

	#[Required(['add'])]
	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[NotEmpty(groups: ['add', 'update'])]
	#[Length(max: 255, groups: ['add', 'update'])]
	public string $name;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	public ?string $comments;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankName;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAddress;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankCode;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAgencyName;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 9, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankRoutingNumber;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 11, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bik;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 6, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $mfo;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 150, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAccountHolderName;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 34, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAccountNumber;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 20, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $iik;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 100, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAccountCurrency;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 34, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $correspondentAccountNumber;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bankAccountType;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 34, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $iban;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 11, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $swift;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 11, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $bic;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 5, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $codeb;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 5, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $codeg;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 2, groups: ['add', 'update'])]
	#[Description(self::TEMPLATE_FIELD_DESCRIPTION)]
	public ?string $rib;
}
