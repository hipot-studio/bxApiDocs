<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Validator\NotEmptyValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotEmpty extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly bool $allowZero = false,
		private readonly bool $allowSpaces = false,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected array $groups = [],
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			(new NotEmptyValidator($this->allowZero, $this->allowSpaces)),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}