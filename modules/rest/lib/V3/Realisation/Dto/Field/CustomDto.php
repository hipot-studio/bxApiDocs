<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Field;

use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

class CustomDto extends Dto
{
	#[Filterable]
	public int $id;

	#[Required]
	#[Filterable]
	public string $entityId;

	#[Filterable]
	#[Required(['add'])]
	public string $name;

	#[Required(['add'])]
	public string $userTypeId;

	#[Editable]
	public ?string $xmlId;

	#[Editable]
	public int $sort;

	#[Editable]
	public bool $isMultiple;

	#[Editable]
	public bool $isMandatory;

	#[InArray(
		validValues: ['N', 'I', 'E', 'S'],
		strict: false,
		showValues: true
	)]
	#[Editable]
	public string $showFilter;

	#[Editable]
	public bool $showInList;

	#[Editable]
	public bool $editInList;

	#[Editable]
	public bool $isSearchable;

	#[Editable]
	public array $settings;

	#[Editable]
	public null|array|string $editFormLabel;

	#[Editable]
	public null|array|string $listColumnLabel;

	#[Editable]
	public null|array|string $listFilterLabel;

	public null|array|string $errorMessage;

	public null|array|string $helpMessage;
}