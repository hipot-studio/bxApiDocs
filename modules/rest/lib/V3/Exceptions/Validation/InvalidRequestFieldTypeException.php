<?php

namespace Bitrix\Rest\V3\Exceptions\Validation;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;

class InvalidRequestFieldTypeException extends RequestValidationException
{
	public function __construct(string $field, string $type)
	{
		$message = new LocalizableMessage(
			code: 'REST_V3_EXCEPTIONS_VALIDATION_INVALIDREQUESTFIELDTYPEEXCEPTION',
			replace: [
				'#FIELD#' => $field,
				'#TYPE#' => class_exists($type) ? (new \ReflectionClass($type))->getShortName() : $type,
			],
		);
		parent::__construct([new Error($message, $field)]);
	}
}
