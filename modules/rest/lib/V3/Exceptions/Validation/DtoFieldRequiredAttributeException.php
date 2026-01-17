<?php

namespace Bitrix\Rest\V3\Exceptions\Validation;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;

class DtoFieldRequiredAttributeException extends RequestValidationException
{
	public function __construct(string $dto, string $field, string $attribute)
	{
		$message = new LocalizableMessage(
			'REST_V3_EXCEPTIONS_VALIDATION_DTOFIELDREQUIREDATTRIBUTEEXCEPTION', [
				'#FIELD#' => $field,
				'#DTO#' => $dto,
				'#ATTRIBUTE#' => (new \ReflectionClass($attribute))->getShortName(),
			],
		);
		parent::__construct([new Error($message, $field)]);
	}
}
