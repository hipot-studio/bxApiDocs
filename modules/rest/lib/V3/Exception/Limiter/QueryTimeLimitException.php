<?php

namespace Bitrix\Rest\V3\Exception\Limiter;

class QueryTimeLimitException extends AbstractTimeLimitException
{
	protected const STATUS = \CRestServer::STATUS_SERVICE_UNAVAILABLE;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_QUERYTIMELIMITEXCEPTION';
	}
}
