<?php

namespace Bitrix\Main\DI\Exception;

use Bitrix\Main\ObjectNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

class ServiceNotFoundException extends ObjectNotFoundException implements NotFoundExceptionInterface
{
}
