<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\ListRequest;

class AppListRequest extends ListRequest
{
	public array $attributes = [];
}
