<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsInArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Dto\Dto;

class ContactCompanyBindingsDto extends Dto
{
	public const MAX_COMPANIES = 50;

	#[Editable]
	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	#[ElementsInArrayFrom(
		ItemProvider::class,
		['entityTypeId' => OwnerType::COMPANY],
		maxElements: self::MAX_COMPANIES,
	)]
	#[Filterable]
	public array $companiesId;
}
