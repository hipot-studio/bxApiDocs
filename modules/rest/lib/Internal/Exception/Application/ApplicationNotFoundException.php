<?php

declare(strict_types = 1);

namespace Bitrix\Rest\Internal\Exception\Application;

use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Rest\Internal\Exception\ExceptionInterface;

class ApplicationNotFoundException extends ObjectNotFoundException implements ExceptionInterface
{
	public function __construct(public string $clientId)
	{
		parent::__construct();
	}
}
