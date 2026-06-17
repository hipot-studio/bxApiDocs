<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Validation\Rule\Template\List;

use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\FilterOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\FilterItem;

class ValueType implements ValidatorInterface
{
	public function __construct(private readonly string $type)
	{
	}

	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if (!$value instanceof FilterItem)
		{
			return $result;
		}

		if ($value->operator === FilterOperator::In && !is_array($value->value))
		{
			$result->addError(
				new ValidationError(sprintf('Field "%s" filter value should be an array', $value->field->value))
			);
		}

		if ($value->operator !== FilterOperator::In && is_array($value->value))
		{
			$result->addError(
				new ValidationError(sprintf('Field "%s" filter value should not be an array', $value->field->value))
			);
		}

		$values = is_array($value->value) ? $value->value : [$value->value];
		foreach ($values as $val)
		{
			if (!$this->checkValue($val))
			{
				$result->addError(
					new ValidationError(
						sprintf(
							'Field "%s" filter values should be of type %s',
							$value->field->value,
							$this->type
						)
					)
				);

				break;
			}
		}

		return $result;
	}

	protected function checkValue(mixed $value): bool
	{
		return gettype($value) === $this->type;
	}
}