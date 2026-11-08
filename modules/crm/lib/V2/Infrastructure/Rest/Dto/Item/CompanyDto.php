<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CurrencyDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\CurrencyIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\CompanyTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\EmployeesDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\IndustryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

class CompanyDto extends AbstractItemDto
{
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::LEAD])]
	public ?int $leadId;
	#[RelationToOne('leadId', 'id')]
	#[CrmRelation(OwnerType::LEAD)]
	public ?ItemDto $lead;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(CurrencyIdProvider::class, showValues: true)]
	public ?string $currencyId;
	#[RelationToOne('currencyId', 'id')]
	public ?CurrencyDto $currency;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Required(['add'])]
	#[Length(max: 255)]
	public string $title;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_INDUSTRY], showValues: true)]
	public ?string $industryId;
	#[RelationToOne('industryId', 'id')]
	public ?IndustryDto $industry;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_EMPLOYEES], showValues: true)]
	public ?string $employeesId;
	#[RelationToOne('employeesId', 'id')]
	public ?EmployeesDto $employees;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?float $revenue;

	#[Editable]
	public ?FileDto $logo;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_COMPANY_TYPE], showValues: true)]
	public ?string $typeId;
	#[RelationToOne('typeId', 'id')]
	public ?CompanyTypeDto $type;
}
