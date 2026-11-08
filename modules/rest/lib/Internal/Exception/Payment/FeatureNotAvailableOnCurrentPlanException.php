<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\Payment;

use Bitrix\Main\SystemException;
use Bitrix\Rest\Internal\Exception\Admission\RestAccessDenialExceptionInterface;

final class FeatureNotAvailableOnCurrentPlanException extends SystemException implements
	FeatureNotAvailableOnCurrentPlanExceptionInterface,
	RestAccessDenialExceptionInterface
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::DEFAULT_MESSAGE, previous: $previous);
	}
}
