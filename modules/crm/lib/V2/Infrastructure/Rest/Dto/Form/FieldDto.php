<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

final class FieldDto extends Dto
{
	public string $name;

	public string $type;

	public ?string $label;

	public int $sort;

	public bool $required;

	public bool $multiple;

	public ?string $placeholder;

	public ?string $valueType;

	public ?string $value;
}
