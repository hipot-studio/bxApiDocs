<?php

declare(strict_types = 1);

namespace Bitrix\Rest\Internal\Exception\Application;

use Bitrix\Main;
use Bitrix\Rest\Internal\Exception\ExceptionInterface;

class ApplicationNotInstalledException extends Main\SystemException implements ExceptionInterface
{
}
