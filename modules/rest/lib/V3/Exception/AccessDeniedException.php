<?php

namespace Bitrix\Rest\V3\Exception;

class AccessDeniedException extends RestException
{
	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_ACCESSDENIEDEXCEPTION';
	}
}
