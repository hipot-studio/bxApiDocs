<?php

namespace Bitrix\Rest\V3\Exception;

class InsufficientScopeException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_INSUFFICIENTSCOPEEXCEPTION';
	}
}
