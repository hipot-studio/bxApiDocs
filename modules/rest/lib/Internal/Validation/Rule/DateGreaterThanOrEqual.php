<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Rest\Internal\Validation\Validator\DateGreaterThanOrEqualValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateGreaterThanOrEqual extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	public function __construct(
		private readonly Date $boundaryDate = new Date(),
		private readonly string $dateFormat = 'Y-m-d',
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected array $groups = [],
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			new DateGreaterThanOrEqualValidator($this->boundaryDate, $this->dateFormat),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}
