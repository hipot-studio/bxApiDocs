<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Access;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;

class ResetAccessRequest extends Request
{
	#[NotEmpty]
	public string $clientId;
}
