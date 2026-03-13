<?php

namespace Bitrix\Rest\V3\Realisation\Dto;

use Bitrix\Rest\V3\Dto\Dto;

class DtoFieldDto extends Dto
{
	public string $name;
	public string $type;
	public ?string $title;
	public ?string $description;
	public array $validationRules;
	public ?array $requiredGroups;
	public bool $filterable;
	public bool $sortable;
	public bool $editable;
	public bool $multiple;
	public ?string $elementType;
}