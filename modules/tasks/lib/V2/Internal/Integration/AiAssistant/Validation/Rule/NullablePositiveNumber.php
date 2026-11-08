<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\MinValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NullablePositiveNumber extends AbstractPropertyValidationAttribute
{
	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		if ($propertyValue === null)
		{
			return new ValidationResult();
		}

		return parent::validateProperty($propertyValue);
	}

	protected function getValidators(): array
	{
		return [
			new MinValidator(1),
		];
	}
}
