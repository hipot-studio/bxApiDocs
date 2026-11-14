<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Settings;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Interaction\Request\Request;

final class GetRequest extends Request
{
	#[NotEmpty]
	#[Description('Stable setting identifier returned by crm.settings.list or crm.settings.get.')]
	public string $id;
}
