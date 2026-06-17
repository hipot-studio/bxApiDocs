<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Validator;

use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;

class TimezoneValidator implements ValidatorInterface
{
	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if (!Container::getTimezoneService()->isValid($value))
		{
			$result->addError(new ValidationError(
				'Timezone invalid',
				failedValidator: $this
			));
		}

		return $result;
	}
}
