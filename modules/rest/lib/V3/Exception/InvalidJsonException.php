<?php

namespace Bitrix\Rest\V3\Exception;

class InvalidJsonException extends RestException implements SkipWriteToLogException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_INVALIDJSONEXCEPTION';
	}

	protected function getClassWithPhrase(): string
	{
		return self::class;
	}
}
