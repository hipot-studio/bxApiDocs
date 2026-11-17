<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\ValidationResult;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class PositiveUserIds implements PropertyValidationAttributeInterface
{
	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();
		$positiveNumber = new PositiveNumber();
		foreach ($propertyValue as $userId)
		{
			if (is_int($userId))
			{
				$result->addErrors($positiveNumber->validateProperty($userId)->getErrors());
			}
		}

		return $result;
	}
}
