<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Validator\PhoneValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Phone extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected array $groups = [],
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			(new PhoneValidator()),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}