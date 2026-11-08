<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\PersonTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\LocationIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\PersonTypeIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;

class QuoteDto extends AbstractItemDto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public string $title;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?Date $actualTime;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $content;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?string $terms;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 100)]
	public ?string $quoteNumber;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::DEAL])]
	public ?int $dealId;
	#[RelationToOne('dealId', 'id')]
	#[CrmRelation(OwnerType::DEAL)]
	public ?ItemDto $deal;

	#[Editable]
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
	#[InArrayFrom(LocationIdProvider::class)]
	public ?int $locationId;

	#[Filterable]
	#[Sortable]
	#[InArrayFrom(PersonTypeIdProvider::class, showValues: true)]
	public ?string $personTypeId;
	#[RelationToOne('personTypeId', 'id')]
	public ?PersonTypeDto $personType;
}
