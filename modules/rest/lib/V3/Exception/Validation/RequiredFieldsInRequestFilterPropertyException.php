<?php

namespace Bitrix\Rest\V3\Exception\Validation;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;

class RequiredFieldsInRequestFilterPropertyException extends RequestFilterValidationException
{
	public function __construct(string $property, array $fields)
	{
		$errors = [];

		foreach ($fields as $field)
		{
			$message = new LocalizableMessage(
				'REST_V3_EXCEPTION_VALIDATION_REQUIREDFIELDSINREQUESTFILTERPROPERTYEXCEPTION', ['#FIELD#' => $field],
			);
			$errors[] = new Error($message, $property . '.' . $field);
		}

		parent::__construct($errors);
	}
}
