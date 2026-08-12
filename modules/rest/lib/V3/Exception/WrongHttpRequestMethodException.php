<?php

namespace Bitrix\Rest\V3\Exception;

class WrongHttpRequestMethodException extends RestException implements SkipWriteToLogException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_WRONGHTTPREQUESTMETHODEXCEPTION';
	}
}
