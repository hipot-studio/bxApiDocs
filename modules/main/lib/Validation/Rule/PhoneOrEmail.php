<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\PhoneValidator;
use Bitrix\Main\Validation\Validator\EmailValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class PhoneOrEmail implements PropertyValidationAttributeInterface, ValidateByGroupInterface
{
	use ValidationErrorTrait;

	protected array $groups;

	public function __construct(
		private readonly bool $strict = false,
		private readonly bool $domainCheck = false,
		string|LocalizableMessageInterface|null $errorMessage = null,
		array $groups = [],
	)
	{
		$this->errorMessage = $errorMessage;
		$this->groups = $groups;
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$phoneResult = (new PhoneValidator())->validate($propertyValue);
		if ($phoneResult->isSuccess())
		{
			return $this->replaceWithCustomError($phoneResult);
		}

		$emailResult = (new EmailValidator($this->strict, $this->domainCheck))->validate($propertyValue);

		return $this->replaceWithCustomError($emailResult);
	}
}