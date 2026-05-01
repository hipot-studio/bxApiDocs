<?php

namespace Bitrix\Rest\V3\Exception;

class AccessDeniedException extends RestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_ACCESSDENIEDEXCEPTION';
	}
}
