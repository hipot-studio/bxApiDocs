<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class AccessDto extends Dto
{
	#[Title('Catalog item identifier')]
	#[Required]
	public int $catalogItemId;

	#[Title('Catalog item access codes')]
	#[Editable]
	#[Required]
	public array $accessCodes;
}
