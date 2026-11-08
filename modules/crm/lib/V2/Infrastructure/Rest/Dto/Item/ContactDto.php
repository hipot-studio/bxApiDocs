<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\Dictionary\HonorificDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\StatusIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\InArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Main\Type\Date;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\RelationToMany;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

class ContactDto extends AbstractItemDto
{
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::COMPANY])]
	public ?int $companyId;
	#[RelationToOne('companyId', 'id')]
	#[CrmRelation(OwnerType::COMPANY)]
	public ?ItemDto $company;

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
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_HONORIFIC], showValues: true)]
	public ?string $honorificId;
	#[RelationToOne('honorificId', 'id')]
	public ?HonorificDto $honorific;

	#[RelationToMany('companiesId', 'id', ['id' => 'ASC'])]
	#[ElementType(ItemDto::class)]
	#[CrmRelation(OwnerType::COMPANY)]
	public ?DtoCollection $companies;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $name;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $secondName;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 50)]
	public ?string $lastName;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[Length(max: 255)]
	public ?string $post;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?Date $birthdayTime;

	#[Editable]
	#[Filterable]
	#[Sortable]
	#[InArrayFrom(StatusIdProvider::class, ['statusTypeId' => StatusTable::ENTITY_ID_CONTACT_TYPE], showValues: true)]
	public ?string $typeId;

	#[Editable]
	#[Filterable]
	#[Sortable]
	public ?bool $export;

	#[Editable]
	public ?FileDto $photo;
}
