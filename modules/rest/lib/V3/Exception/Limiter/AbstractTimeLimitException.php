<?php

namespace Bitrix\Rest\V3\Exception\Limiter;

use Bitrix\Rest\V3\Exception\RestException;

abstract class AbstractTimeLimitException extends RestException implements TimeLimitExceptionInterface
{
	public function __construct(private int $retryAfter = 0, ?\Throwable $previous = null)
	{
		parent::__construct($previous);
	}

	public function getRetryAfter(): int
	{
		return $this->retryAfter;
	}
}
