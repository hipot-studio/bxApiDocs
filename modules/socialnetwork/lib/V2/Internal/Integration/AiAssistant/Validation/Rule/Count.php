<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Validation\Rule;

use Attribute;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Count implements PropertyValidationAttributeInterface
{
	/**
	 * @throws ArgumentException
	 */
	public function __construct(
		private readonly ?int $min = null,
		private readonly ?int $max = null,
	)
	{
		if ($this->min === null && $this->max === null)
		{
			throw new ArgumentException('At least one of min or max must be specified');
		}
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();

		if (!is_countable($propertyValue))
		{
			return $result->addError(new ValidationError('Value must be countable'));
		}

		$count = count($propertyValue);

		if ($this->min !== null && $count < $this->min)
		{
			$result->addError(new ValidationError(
				"Items count {$count} is less than minimum {$this->min}",
			));
		}

		if ($this->max !== null && $count > $this->max)
		{
			$result->addError(new ValidationError(
				"Items count {$count} is greater than maximum {$this->max}",
			));
		}

		return $result;
	}
}
