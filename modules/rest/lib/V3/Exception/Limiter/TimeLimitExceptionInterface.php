<?php

namespace Bitrix\Rest\V3\Exception\Limiter;

interface TimeLimitExceptionInterface
{
	public function getRetryAfter(): int;
}
