<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Main;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

class UpdateIncomingWebhookRequest extends Request
{
	public string $id;

	public FieldsStructure $fields;
}
