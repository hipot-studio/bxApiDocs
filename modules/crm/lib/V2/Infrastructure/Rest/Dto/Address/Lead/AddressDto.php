<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Lead;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\Length;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('lead address')]
final class AddressDto extends Dto
{
	#[Editable(['set'])]
	#[Length(max: 1024)]
	public string $address1;

	#[Editable(['set'])]
	#[Length(max: 1024)]
	public string $address2;

	#[Editable(['set'])]
	#[Length(max: 128)]
	public string $city;

	#[Editable(['set'])]
	#[Length(max: 16)]
	public string $postalCode;

	#[Editable(['set'])]
	#[Length(max: 128)]
	public string $region;

	#[Editable(['set'])]
	#[Length(max: 128)]
	public string $province;

	#[Editable(['set'])]
	#[Length(max: 128)]
	public string $country;

	#[Editable(['set'])]
	#[Length(max: 100)]
	public string $countryCode;
}
