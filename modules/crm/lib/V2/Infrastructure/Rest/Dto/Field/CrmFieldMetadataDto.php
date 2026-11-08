<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM field definition')]
final class CrmFieldMetadataDto extends Dto
{
	public string $name;
	public string $type;
	public ?string $elementType;
	public bool $multiple;
	public ?string $title;
	public ?string $description;
	public array $validationRules;
	public ?array $requiredGroups;
	public bool $filterable;
	public bool $sortable;
	public bool $editable;
}
