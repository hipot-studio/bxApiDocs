<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Validation\Validator;

use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;

class ExternalAttributesValidator implements ValidatorInterface
{
	public const DEFAULT_MAX_COUNT = 10;
	public const DEFAULT_MAX_CODE_LENGTH = 50;
	public const DEFAULT_MAX_VALUE_LENGTH = 1000;

	public function __construct(
		private readonly int $maxCount = self::DEFAULT_MAX_COUNT,
		private readonly int $maxCodeLength = self::DEFAULT_MAX_CODE_LENGTH,
		private readonly int $maxValueLength = self::DEFAULT_MAX_VALUE_LENGTH,
	)
	{
	}

	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if (!is_array($value))
		{
			$result->addError(new ValidationError(
				'External attributes must be an array',
				failedValidator: $this,
			));

			return $result;
		}

		if (count($value) > $this->maxCount)
		{
			$result->addError(new ValidationError(
				"Too many attributes, max {$this->maxCount} allowed",
				failedValidator: $this,
			));
		}

		foreach ($value as $code => $itemValue)
		{
			if (!is_string($code) || $code === '' || mb_strlen($code) > $this->maxCodeLength)
			{
				$result->addError(new ValidationError(
					"Attribute code must be a non-empty string up to {$this->maxCodeLength} chars",
					failedValidator: $this,
				));

				continue;
			}

			if ($itemValue !== null && !is_string($itemValue))
			{
				$result->addError(new ValidationError(
					"Attribute \"{$code}\" value must be string or null",
					failedValidator: $this,
				));

				continue;
			}

			if (is_string($itemValue) && mb_strlen($itemValue) > $this->maxValueLength)
			{
				$result->addError(new ValidationError(
					"Attribute \"{$code}\" value exceeds {$this->maxValueLength} chars",
					failedValidator: $this,
				));
			}
		}

		return $result;
	}
}
