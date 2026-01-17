<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

use Attribute;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\AtLeastOneNotEmptyValidator;
use ReflectionClass;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_CLASS)]
final class OnlyOneOfPropertyRequired extends AbstractClassValidationAttribute
{
	public function __construct(
		private readonly array $propertyNames,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
	)
	{
	}

	public function validateObject(object $object): ValidationResult
	{
		$result = new ValidationResult();

		$properties = $this->getProperties($object);

		if (empty($properties))
		{
			return $this->resultWithError($result);
		}

		$values = $this->getValues($object, ...$properties);
		if (count($values) !== 1)
		{
			return $this->resultWithError($result);
		}

		return $result;
	}

	private function getProperties(object $object): array
	{
		$reflection = new ReflectionClass($object);

		return array_filter(
			$reflection->getProperties(),
			fn (ReflectionProperty $property): bool =>
			in_array($property->getName(), $this->propertyNames, true)
		);
	}

	private function getValues(object $object, ReflectionProperty ...$properties): array
	{
		$values = [];
		foreach ($properties as $property)
		{
			if ($property->isInitialized($object) && !empty($property->getValue($object)))
			{
				$values[] = $property->getValue($object);
			}
		}

		return $values;
	}

	private function resultWithError(ValidationResult $result): ValidationResult
	{
		$message = new LocalizableMessage(
			'MAIN_VALIDATION_ONLY_ONE_OF_PROPERTY_REQUIRED',
			['#PROPERTY_NAMES#' => join(', ', $this->propertyNames)]
		);

		$result->addError(new ValidationError($message));

		return $this->replaceWithCustomError($result);
	}
}