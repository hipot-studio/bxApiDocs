<?php

namespace Bitrix\Rest\V3\Exception;

class RateLimitException extends RestException
{
	protected const STATUS = \CRestServer::STATUS_TO_MANY_REQUESTS;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_RATELIMITEXCEPTION';
	}
}
