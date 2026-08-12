<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Validator\AccessTypeValidator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class AccessType extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
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
			new AccessTypeValidator(),
		];
	}

	public function getGroups(): array
	{
		return $this->groups;
	}
}
