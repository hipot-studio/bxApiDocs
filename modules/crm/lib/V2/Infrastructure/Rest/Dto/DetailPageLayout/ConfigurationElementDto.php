<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\DetailPageLayout;

use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Dto\Dto;

final class ConfigurationElementDto extends Dto
{
	#[Editable]
	#[Required]
	#[NotEmpty(allowZero: true, allowSpaces: true)]
	public string $name;

	#[Editable]
	public int $optionFlags;

	#[Editable]
	public object $options;
}
