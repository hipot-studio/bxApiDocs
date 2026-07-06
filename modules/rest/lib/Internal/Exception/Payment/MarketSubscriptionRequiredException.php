<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Payment;

use Bitrix\Main;

class MarketSubscriptionRequiredException extends Main\SystemException implements PaymentRequiredExceptionInterface
{
}
