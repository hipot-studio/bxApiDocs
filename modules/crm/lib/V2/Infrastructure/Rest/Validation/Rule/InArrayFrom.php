<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ValidValuesProviderInterface;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\ValidValuesCache;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\Rule\AbstractPropertyValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Rest\Exceptions\ArgumentTypeException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InArrayFrom extends AbstractPropertyValidationAttribute implements ValidateByGroupInterface
{
	protected ?Context $context = null;

	public function __construct(
		protected readonly string|ValidValuesProviderInterface $provider,
		protected array $contextArgs = [],
		protected readonly bool $strict = false,
		protected string|LocalizableMessageInterface|null $errorMessage = null,
		protected readonly bool $showValues = false,
		protected array $groups = [],
	)
	{
		if (!is_subclass_of($provider, ValidValuesProviderInterface::class))
		{
			throw new ArgumentTypeException(
				'provider',
				ValidValuesProviderInterface::class,
			);
		}
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		return $this->validateWithContext($propertyValue, $this->buildContext($propertyValue));
	}

	protected function validateWithContext(mixed $propertyValue, Context $context): ValidationResult
	{
		$this->context = $context;

		try
		{
			return parent::validateProperty($propertyValue);
		}
		finally
		{
			$this->context = null;
		}
	}

	public function hasContextArg(string $name): bool
	{
		return array_key_exists($name, $this->contextArgs);
	}

	public function addContextArg(string $name, mixed $value): self
	{
		$this->contextArgs[$name] = $value;

		return $this;
	}

	protected function buildContext(mixed $propertyValue): Context
	{
		return new Context(
			(int)CurrentUser::get()->getId(),
			$propertyValue,
			$this->showValues,
			$this->contextArgs,
		);
	}

	protected function getValidators(): array
	{
		return [
			(new InArrayValidator($this->getValidValues(), $this->strict, $this->showValues)),
		];
	}

	protected function getValidValues(): array
	{
		if (!isset($this->context))
		{
			return [];
		}

		return ValidValuesCache::get($this->provider, $this->context);
	}

	public static function __set_state(array $array): static
	{
		return new static(
			provider: $array['provider'] ?? '',
			contextArgs: $array['contextArgs'] ?? [],
			strict: $array['strict'] ?? null,
			errorMessage: $array['errorMessage'] ?? null,
			showValues: $array['showValues'] ?? false,
			groups: $array['groups'] ?? [],
		);
	}
}
