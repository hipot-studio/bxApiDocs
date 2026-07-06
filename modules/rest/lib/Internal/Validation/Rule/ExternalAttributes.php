<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Rest\Internal\Validation\Validator\ExternalAttributesValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExternalAttributes extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly int $maxCount = ExternalAttributesValidator::DEFAULT_MAX_COUNT,
		private readonly int $maxCodeLength = ExternalAttributesValidator::DEFAULT_MAX_CODE_LENGTH,
		private readonly int $maxValueLength = ExternalAttributesValidator::DEFAULT_MAX_VALUE_LENGTH,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected array $groups = [],
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			new ExternalAttributesValidator(
				$this->maxCount,
				$this->maxCodeLength,
				$this->maxValueLength,
			),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}
