<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Validation\Rule\Template\List;

use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\FilterItem;

class AllowedWithOperator implements ValidatorInterface
{
	public function __construct(private readonly array $allowedOperators)
	{
	}

	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if (!$value instanceof FilterItem)
		{
			return $result;
		}

		if (!in_array($value->operator, $this->allowedOperators))
		{
			$result->addError(
				new ValidationError(
					sprintf('Field "%s" is not allowed with operator "%s"', $value->field->value, $value->operator->value)
				)
			);
		}

		return $result;
	}
}