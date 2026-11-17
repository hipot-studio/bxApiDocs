<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\Min;
use Bitrix\Main\Validation\ValidationResult;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NullableMin extends Min
{
	public function validateProperty(mixed $value): ValidationResult
	{
		if ($value === null)
		{
			return new ValidationResult();
		}

		return parent::validateProperty($value);
	}
}
