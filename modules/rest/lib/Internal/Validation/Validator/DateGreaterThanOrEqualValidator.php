<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Validation\Validator;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;

class DateGreaterThanOrEqualValidator implements ValidatorInterface
{
	public function __construct(
		private readonly Date $boundaryDate = new Date(),
		private string $dateFormat = 'Y-m-d',
	)
	{
	}

	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if ($value === null)
		{
			return $result;
		}

		if ($value instanceof Date)
		{
			return $this->validateDate($value, $result);
		}

		if (!is_string($value))
		{
			return $result->addError(new ValidationError(
				new LocalizableMessage(
					code: 'REST_VALIDATION_DATE_GREATER_THAN_OR_EQUAL_INVALID_FORMAT',
					replace: ['#FORMAT#' => $this->dateFormat],
				),
				failedValidator: $this,
			));
		}

		if (
			!Date::isCorrect($value, $this->dateFormat)
			|| (new Date($value, $this->dateFormat))->format($this->dateFormat) !== $value
		)
		{
			return $result->addError(new ValidationError(
				new LocalizableMessage(
					code: 'REST_VALIDATION_DATE_GREATER_THAN_OR_EQUAL_INVALID_FORMAT',
					replace: ['#FORMAT#' => $this->dateFormat],
				),
				failedValidator: $this,
			));
		}

		return $this->validateDate(new Date($value, $this->dateFormat), $result);
	}


	private function validateDate(Date $date, ValidationResult $result): ValidationResult
	{
		if ($date < $this->boundaryDate)
		{
			$result->addError(new ValidationError(
				new LocalizableMessage(
					code: 'REST_VALIDATION_DATE_GREATER_THAN_OR_EQUAL_INVALID',
					replace: ['#BOUNDARY#' => $this->boundaryDate->format($this->dateFormat)],
				),
				failedValidator: $this,
			));
		}

		return $result;
	}
}
