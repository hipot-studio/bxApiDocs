<?php

namespace Bitrix\Main\Infrastructure\Rest\Request\User;

use Bitrix\Rest\V3\Interaction\Request\GetRequest;

class AgreementGetRequest extends GetRequest
{
	/**
	 * Placeholder values substituted into the agreement label and text.
	 */
	public ?array $replace = null;
}
