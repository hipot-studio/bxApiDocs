<?php

declare(strict_types = 1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class AccessDto extends Dto
{
	#[Title('Application client ID')]
	#[Required]
	public string $clientId;

	#[Title('Application access codes')]
	#[Editable, Required]
	public array $codes;

	#[Title('Access code details with provider and display name')]
	#[Optional]
	public ?array $codesDetails = null;
}
