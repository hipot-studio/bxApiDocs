<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\User\EmployeeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ObserverUserIdProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsInArrayFrom;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToMany;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

class ObserversDto extends Dto
{
	#[Editable]
	#[ElementsType(Type::Integer)]
	#[ElementType('int')]
	#[ElementsInArrayFrom(ObserverUserIdProvider::class)]
	#[Filterable]
	public array $observersId;

	#[RelationToMany('observersId', 'id', ['id' => 'ASC'])]
	#[ElementType(EmployeeDto::class)]
	public ?DtoCollection $observers;
}
