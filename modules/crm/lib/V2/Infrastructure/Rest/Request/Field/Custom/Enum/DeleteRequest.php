<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Custom\Enum;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class DeleteRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;
}
