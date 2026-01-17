<?php
namespace Bitrix\Rest\V3\Exception\Validation;

/**
 * Exception thrown when a file/chunk validation fails.
 */
class InvalidFileException extends ValidationException
{
	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTIONS_VALIDATION_INVALIDFILEEXCEPTION';
	}
}