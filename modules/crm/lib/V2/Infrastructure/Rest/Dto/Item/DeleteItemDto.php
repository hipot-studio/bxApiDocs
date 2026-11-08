<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Dto\Dto;

final class DeleteItemDto extends Dto
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;
}
