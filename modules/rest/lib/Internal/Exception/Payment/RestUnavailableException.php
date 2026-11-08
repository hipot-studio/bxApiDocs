<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Payment;

use Bitrix\Main;
use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

class RestUnavailableException extends Main\SystemException implements
	PaymentRequiredExceptionInterface,
	RestAccessDenialExceptionInterface
{
}
