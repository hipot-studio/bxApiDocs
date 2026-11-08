<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\MyCompany;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\MultifieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\MyCompany\BasicCompanyDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\BankRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\RequisiteDto;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

#[Title('own company basic details')]
#[MappedBy(BasicCompanyDtoMapper::class)]
class BasicCompanyDto extends Dto
{
	#[Filterable]
	#[Sortable]
	public int $id;

	#[Filterable]
	#[Sortable]
	public ?string $title;

	#[Filterable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $email;

	#[Filterable]
	#[ElementType(MultifieldDto::class)]
	public ?DtoCollection $phone;

	#[ElementType(RequisiteDto::class)]
	public ?DtoCollection $requisites;

	#[ElementType(BankRequisiteDto::class)]
	public ?DtoCollection $bankRequisites;
}
