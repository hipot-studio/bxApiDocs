<?php

namespace Bitrix\Rest\V3\Exceptions;

class WrongHttpRequestMethodException extends RestException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_WRONGHTTPREQUESTMETHODEXCEPTION';
	}
}
