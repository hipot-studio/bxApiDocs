<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Validator\InArrayValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InArray extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly array $validValues,
		private readonly bool $strict = false,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		private readonly bool $showValues = false,
		protected array $groups = [],
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			(new InArrayValidator($this->validValues, $this->strict, $this->showValues)),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}
