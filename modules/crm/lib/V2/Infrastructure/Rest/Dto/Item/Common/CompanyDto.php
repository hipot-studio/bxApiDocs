<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

class CompanyDto extends Dto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::COMPANY])]
	public ?int $companyId;

	#[RelationToOne('companyId', 'id')]
	#[CrmRelation(OwnerType::COMPANY)]
	public ?ItemDto $company;
}
