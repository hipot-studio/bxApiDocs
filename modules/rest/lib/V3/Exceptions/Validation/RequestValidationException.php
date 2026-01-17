<?php

namespace Bitrix\Rest\V3\Exceptions\Validation;

abstract class RequestValidationException extends ValidationException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_VALIDATION_REQUESTVALIDATIONEXCEPTION';
	}

	protected function getClassWithPhrase(): string
	{
		return self::class;
	}
}
