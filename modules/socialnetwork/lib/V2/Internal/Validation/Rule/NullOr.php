<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationResult;

/**
 * Generic nullable wrapper for any validation attribute.
 *
 * Skips validation when the value is null, otherwise delegates
 * to the wrapped attribute.
 *
 * Usage:
 *   #[NullOr(PositiveNumber::class)]
 *   public readonly ?int $ownerId = null,
 *
 *   #[NullOr(Min::class, min: 0)]
 *   public readonly ?int $count = null,
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NullOr implements PropertyValidationAttributeInterface, ValidateByGroupInterface
{
	private readonly PropertyValidationAttributeInterface $rule;

	/**
	 * @param class-string<PropertyValidationAttributeInterface> $ruleClass
	 */
	public function __construct(
		string $ruleClass,
		mixed ...$args,
	)
	{
		$this->rule = new $ruleClass(...$args);
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		if ($propertyValue === null)
		{
			return new ValidationResult();
		}

		return $this->rule->validateProperty($propertyValue);
	}

	public function getGroups(): array
	{
		if ($this->rule instanceof ValidateByGroupInterface)
		{
			return $this->rule->getGroups();
		}

		return [];
	}
}
