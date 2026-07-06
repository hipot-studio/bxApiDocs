<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class GetClientIdRequest extends Request
{
	#[NotEmpty]
	public string $clientId;

	public ?SelectStructure $select = null;
}
