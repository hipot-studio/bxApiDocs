<?php

declare(strict_types = 1);

namespace Bitrix\Rest\Internal\Exception\IncomingWebhook;

use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Exception\ExceptionInterface;

class CannotProlongIncomingWebhookException extends SystemException implements ExceptionInterface
{
	public function __construct()
	{
		parent::__construct('Incoming webhook cannot be prolonged');
	}
}
