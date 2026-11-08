<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('address in CRM company or contact details')]
final class RequisiteAddressDto extends Dto
{
	#[Editable(['add'])]
	#[Filterable]
	#[Required(['add'])]
	#[PositiveNumber]
	public int $requisiteId;

	#[Editable(['add'])]
	#[Filterable]
	#[Required(['add'])]
	#[Sortable]
	#[PositiveNumber]
	public int $typeId;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 1024)]
	public string $address1;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 1024)]
	public string $address2;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 128)]
	public string $city;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 16)]
	public string $postalCode;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 128)]
	public string $region;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 128)]
	public string $province;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 128)]
	public string $country;

	#[Editable(['add', 'update'])]
	#[Filterable]
	#[Sortable]
	#[Length(max: 100)]
	public string $countryCode;
}
