<?php

declare(strict_types=1);

namespace Bitrix\Main\DI\Exception;

use Bitrix\Main\SystemException;
use Psr\Container\ContainerExceptionInterface;

class RegistrationException extends SystemException implements ContainerExceptionInterface
{
}
