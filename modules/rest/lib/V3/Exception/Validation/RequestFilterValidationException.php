<?php

namespace Bitrix\Rest\V3\Exception\Validation;

class RequestFilterValidationException extends ValidationException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_VALIDATION_REQUESTFILTERVALIDATIONEXCEPTION';
	}

	protected function getClassWithPhrase(): string
	{
		return self::class;
	}
}
