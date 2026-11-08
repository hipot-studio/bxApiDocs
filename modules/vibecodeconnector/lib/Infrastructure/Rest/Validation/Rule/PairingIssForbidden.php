<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractClassValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;

#[Attribute(Attribute::TARGET_CLASS)]
final class PairingIssForbidden extends AbstractClassValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly string $property = 'iss',
		protected string|LocalizableMessageInterface|null $errorMessage = 'iss is assigned by the portal and cannot be supplied',
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
		$property = new \ReflectionProperty($object, $this->property);
		if (!$property->isInitialized($object) || $property->getValue($object) === null)
		{
			return $result;
		}

		$result->addError(new ValidationError($this->errorMessage, $this->property));

		return $result;
	}
}
