<?php

namespace Bitrix\Rest\V3\Exception\Validation;


class DtoValidationException extends ValidationException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_VALIDATION_DTOVALIDATIONEXCEPTION';
	}
}
