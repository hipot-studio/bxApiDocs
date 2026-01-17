<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidJsonException extends RestException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_INVALIDJSONEXCEPTION';
	}

	protected function getClassWithPhrase(): string
	{
		return self::class;
	}
}
