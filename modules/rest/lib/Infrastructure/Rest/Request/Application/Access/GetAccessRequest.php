<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Access;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class GetAccessRequest extends Request
{
	#[NotEmpty]
	public string $clientId;

	public ?SelectStructure $select = null;
}
