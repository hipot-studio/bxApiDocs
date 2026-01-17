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

		$timezones = Container::getTimezoneService()->getTimezoneList();

		$timezoneIds = array_column($timezones, 'timezoneId');

		if (!in_array($value, $timezoneIds, true))
		{
			$result->addError(new ValidationError(
				'Timezone invalid',
				failedValidator: $this
			));
		}

		return $result;
	}
}
