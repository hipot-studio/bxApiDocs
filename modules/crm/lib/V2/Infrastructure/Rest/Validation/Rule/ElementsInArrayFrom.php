<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\Context;
use Bitrix\Crm\V2\Infrastructure\Rest\Validation\Provider\ValidValuesProviderInterface;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\Exceptions\ArgumentTypeException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ElementsInArrayFrom extends InArrayFrom
{
	private ?array $collectionValidValues = null;

	public function __construct(
		string|ValidValuesProviderInterface $provider,
		array $contextArgs = [],
		bool $strict = false,
		string|LocalizableMessageInterface|null $errorMessage = null,
		bool $showValues = false,
		array $groups = [],
		private readonly ?int $maxElements = null,
	)
	{
		parent::__construct($provider, $contextArgs, $strict, $errorMessage, $showValues, $groups);
	}

	public function validateProperty(mixed $propertyValue): ValidationResult
	{
		$result = new ValidationResult();
		$failedValidator = null;

		if (!is_iterable($propertyValue))
		{
			throw new ArgumentTypeException('propertyValue', 'iterable');
		}
		if ($this->maxElements !== null && is_countable($propertyValue) && count($propertyValue) > $this->maxElements)
		{
			$result->addError(new ValidationError(
				sprintf('The collection must not contain more than %d elements.', $this->maxElements),
			));

			return $result;
		}

		$context = $this->buildContext($propertyValue);
		$previousValidValues = $this->collectionValidValues;
		$this->collectionValidValues = null;

		try
		{
			foreach ($propertyValue as $value)
			{
				$valueResult = $this->validateWithContext($value, $context);
				$result->addErrors($valueResult->getErrors());
				$failedValidator ??= $this->getFailedValidator($valueResult);
			}
		}
		finally
		{
			$this->collectionValidValues = $previousValidValues;
		}

		return $this->replaceWithCustomError($result, $failedValidator);
	}

	protected function validateWithContext(mixed $propertyValue, Context $context): ValidationResult
	{
		$previousContext = $this->context;

		try
		{
			return parent::validateWithContext($propertyValue, $context);
		}
		finally
		{
			$this->context = $previousContext;
		}
	}

	protected function getValidValues(): array
	{
		if ($this->collectionValidValues === null)
		{
			$this->collectionValidValues = parent::getValidValues();
		}

		return $this->collectionValidValues;
	}

	public static function __set_state(array $array): static
	{
		return new static(
			provider: $array['provider'] ?? '',
			contextArgs: $array['contextArgs'] ?? [],
			strict: $array['strict'] ?? false,
			errorMessage: $array['errorMessage'] ?? null,
			showValues: $array['showValues'] ?? false,
			groups: $array['groups'] ?? [],
			maxElements: $array['maxElements'] ?? null,
		);
	}
}
