<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form;

use Bitrix\Rest\V3\Dto\Dto;

final class DependencyConditionDto extends Dto
{
	public string $fieldName;

	public string $event;

	public ?string $value;

	public ?string $operation;
}
