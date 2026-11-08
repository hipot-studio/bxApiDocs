<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Payment;

use Bitrix\Rest\Internal\Exception\ExceptionInterface;

interface FeatureNotAvailableOnCurrentPlanExceptionInterface extends ExceptionInterface
{
	public const ERROR_CODE = 'FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	public const DEFAULT_MESSAGE = 'Feature is not available on the current plan.';
}
