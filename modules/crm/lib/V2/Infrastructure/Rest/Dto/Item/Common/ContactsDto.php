<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Attribute\CrmRelation;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ItemProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsInArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToMany;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class ContactsDto extends Dto
{
	#[Filterable]
	public ?int $contactId;

	#[RelationToOne('contactId', 'id')]
	#[CrmRelation(OwnerType::CONTACT)]
	public ?ItemDto $contact;

	#[Editable]
	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	#[ElementsInArrayFrom(ItemProvider::class, ['entityTypeId' => OwnerType::CONTACT])]
	#[Filterable]
	public array $contactsId;

	#[RelationToMany('contactsId', 'id', ['id' => 'ASC'])]
	#[ElementType(ItemDto::class)]
	#[CrmRelation(OwnerType::CONTACT)]
	public ?DtoCollection $contacts;
}
