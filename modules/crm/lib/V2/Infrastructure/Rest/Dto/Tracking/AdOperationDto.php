<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking;

use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

#[Title('CRM tracking advertising report progress')]
final class AdOperationDto extends Dto
{
	public bool $complete;

	public ?string $label;
}
