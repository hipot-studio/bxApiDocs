<?php

namespace Bitrix\Rest\V3\Interaction\Response;

use Bitrix\Rest\V3\Dto\Dto;

class CursorResponseDto extends Dto
{
	public ?string $field;
	public ?string $value;
}
