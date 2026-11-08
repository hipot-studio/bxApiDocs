<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\CustomFieldConfigMapper;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(CustomFieldConfigMapper::class)]
final class CustomFieldConfigDto extends Dto
{
	public const SUPPORTED_USER_TYPE_IDS = [
		'integer',
		'double',
		'string',
		'rich_text',
		'boolean',
		'url',
		'datetime',
		'date',
		'money',
		'address',
		'crm',
		'crm_status',
		'employee',
		'iblock_section',
		'iblock_element',
	];

	#[Filterable]
	#[Sortable]
	public int $id;

	#[Filterable]
	public string $entityId;

	#[Filterable]
	#[Sortable]
	#[Required(['add'])]
	#[Editable(['add'])]
	public string $name;

	#[Required(['add'])]
	#[Editable(['add'])]
	#[InArray(validValues: self::SUPPORTED_USER_TYPE_IDS, strict: true, showValues: true)]
	public string $userTypeId;

	#[Editable]
	public ?string $xmlId;

	#[Editable]
	public int $sort;

	#[Editable(['add'])]
	public bool $isMultiple;

	#[Editable]
	public bool $isMandatory;

	#[Editable]
	#[InArray(validValues: ['N', 'I', 'E', 'S'], strict: true, showValues: true)]
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

	#[Editable]
	public null|array|string $errorMessage;

	#[Editable]
	public null|array|string $helpMessage;
}
