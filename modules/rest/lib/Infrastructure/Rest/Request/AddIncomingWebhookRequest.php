<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Rest\Internal\Validation\Rule\ExternalAttributes;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

class AddIncomingWebhookRequest extends Request
{
	public string $title;
	public array $scopes;

	#[ExternalAttributes]
	public array $attributes = [];

	public ?SelectStructure $select = null;
}
