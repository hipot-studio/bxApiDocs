<?php

namespace Bitrix\Rest\V3\Exception;

use Bitrix\Rest\V3\Exception\Limiter\AbstractTimeLimitException;

class RateLimitException extends AbstractTimeLimitException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_TO_MANY_REQUESTS;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_RATELIMITEXCEPTION';
	}
}
