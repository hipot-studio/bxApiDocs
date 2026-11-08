<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Main;
use Bitrix\Rest\V3\Interaction\Request\Request;

class DeleteIncomingWebhookRequest extends Request
{
	public string $id;
}
