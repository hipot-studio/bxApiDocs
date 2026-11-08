<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Rest\Internal\Validation\Rule\DateGreaterThanOrEqual;
use Bitrix\Rest\V3\Interaction\Request\Request;

class ProlongIncomingWebhookRequest extends Request
{
	public string $id;

	#[DateGreaterThanOrEqual]
	public string $dateExpire;
}
