<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractClassValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

#[Attribute(Attribute::TARGET_CLASS)]
final class ViewUrlRequiredForApplication extends AbstractClassValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly string $typeProperty = 'type',
		private readonly string $viewUrlProperty = 'viewUrl',
		protected string|LocalizableMessageInterface|null $errorMessage = 'viewUrl is required for application items',
		protected array $groups = [],
	)
	{
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public function validateObject(object $object): ValidationResult
	{
		$result = new ValidationResult();
		$type = $this->getPropertyValue($object, $this->typeProperty);

		if (!is_string($type) || CatalogItemType::tryFrom($type) !== CatalogItemType::Application)
		{
			return $result;
		}

		$viewUrl = $this->getPropertyValue($object, $this->viewUrlProperty);
		if (is_string($viewUrl) && trim($viewUrl) !== '')
		{
			return $result;
		}

		$result->addError(new ValidationError($this->errorMessage, $this->viewUrlProperty));

		return $result;
	}

	private function getPropertyValue(object $object, string $propertyName): mixed
	{
		$reflection = new \ReflectionClass($object);
		if (!$reflection->hasProperty($propertyName))
		{
			return null;
		}

		$property = $reflection->getProperty($propertyName);
		if (!$property->isInitialized($object))
		{
			return null;
		}

		return $property->getValue($object);
	}
}
