<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\DealTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\StageDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\LocationIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;

class DealDto extends AbstractItemDto
{
	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public string $title;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_DEAL_TYPE], showValues: true)]
	public ?string $typeId;
	#[RelationToOne('typeId', 'id')]
	public ?DealTypeDto $type;

	#[Filterable]
	#[Sortable]
	#[InArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::QUOTE])]
	public ?int $quoteId;
	#[RelationToOne('quoteId', 'id')]
	#[CrmRelation(OwnerType::QUOTE)]
	public ?ItemDto $quote;
	#[Filterable]
	#[Sortable]
	public ?int $leadId;
	#[RelationToOne('leadId', 'id')]
	#[CrmRelation(OwnerType::LEAD)]
	public ?ItemDto $lead;
	#[Filterable]
	#[Sortable]
	public ?string $previousStageId;
	#[RelationToOne('previousStageId', 'id')]
	public ?StageDto $previousStage;

	#[Editable]
	#[InArrayFrom(LocationIdProvider::class)]
	public ?int $locationId;
	#[Filterable]
	#[Sortable]
	public ?bool $isNew;
	#[Filterable]
	#[Sortable]
	public bool $isRepeatedApproach;
	#[Filterable]
	#[Sortable]
	public bool $isReturnCustomer;
}
