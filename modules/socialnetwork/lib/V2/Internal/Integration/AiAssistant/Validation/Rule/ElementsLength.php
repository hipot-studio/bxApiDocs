<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Validation\Rule;

use Attribute;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ElementsLength implements PropertyValidationAttributeInterface
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

		if (!is_iterable($propertyValue))
		{
			return $result->addError(new ValidationError('Value must be iterable'));
		}

		foreach ($propertyValue as $index => $item)
		{
			if (!is_string($item))
			{
				$result->addError(new ValidationError("Item [{$index}] must be a string"));

				continue;
			}

			$length = mb_strlen($item);

			if ($this->min !== null && $length < $this->min)
			{
				$result->addError(new ValidationError(
					"Item [{$index}] length {$length} is less than minimum {$this->min}",
				));

				continue;
			}

			if ($this->max !== null && $length > $this->max)
			{
				$result->addError(new ValidationError(
					"Item [{$index}] length {$length} is greater than maximum {$this->max}",
				));
			}
		}

		return $result;
	}
}
