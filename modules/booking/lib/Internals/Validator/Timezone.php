<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Validator;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Timezone extends AbstractPropertyValidationAttribute
{
	public function __construct(
		protected string|LocalizableMessageInterface|null $errorMessage = null
	)
	{
	}

	protected function getValidators(): array
	{
		return [
			new TimezoneValidator(),
		];
	}
}
