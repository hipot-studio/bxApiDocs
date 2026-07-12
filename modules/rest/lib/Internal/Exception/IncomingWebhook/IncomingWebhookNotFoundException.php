<?php

declare(strict_types = 1);

namespace Bitrix\Rest\Internal\Exception\IncomingWebhook;

use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Exception\ExceptionInterface;

class IncomingWebhookNotFoundException extends ObjectNotFoundException implements ExceptionInterface
{
	public function __construct()
	{
		parent::__construct('Incoming webhook not found');
	}
}
