<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Payment;

use Bitrix\Main;
use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

class MarketSubscriptionRequiredException extends Main\SystemException implements
	PaymentRequiredExceptionInterface,
	RestAccessDenialExceptionInterface
{
}
