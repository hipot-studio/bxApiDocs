<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Rule;

use Attribute;
use Bitrix\Main\Validation\Rule\AbstractClassValidationAttribute;
use Bitrix\Main\Validation\Rule\ValidateByGroupInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\LengthValidator;
use Bitrix\Main\Validation\Validator\MinValidator;
use Bitrix\Main\Validation\Validator\UrlValidator;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Vibecodeconnector\Internal\Model\Catalog\CatalogItemTable;

#[Attribute(Attribute::TARGET_CLASS)]
final class OptionalCatalogItemFields extends AbstractClassValidationAttribute implements ValidateByGroupInterface
{
	private const ICON_URL_MAX_LENGTH = 2000;

	public function __construct(
		protected array $groups = [],
	)
	{
	}

	public function getGroups(): array
	{
		return $this->groups;
	}

	public function validateObject(object $object): ValidationResult
	{
		$result = new ValidationResult();

		$this->validateNullableProperty($result, $object, 'editUrl', [
			new UrlValidator(),
			new LengthValidator(max: CatalogItemTable::MAX_URL_LENGTH),
		]);
		$this->validateNullableProperty($result, $object, 'viewUrl', [
			new UrlValidator(),
			new LengthValidator(max: CatalogItemTable::MAX_URL_LENGTH),
		]);
		// An empty iconUrl resets the icon per the REST contract (IconFileResolver
		// normalizes '' to null), so skip URL validation for '' instead of rejecting it.
		$iconUrl = $this->getPropertyValue($object, 'iconUrl');
		if ($iconUrl !== '')
		{
			$this->validateNullableProperty($result, $object, 'iconUrl', [
				new UrlValidator(),
				new LengthValidator(max: self::ICON_URL_MAX_LENGTH),
			]);
		}
		$this->validateNullableProperty($result, $object, 'chatId', [
			new MinValidator(1),
		]);
		$this->validateNullableProperty($result, $object, 'externalId', [
			new LengthValidator(max: 255),
		]);

		return $result;
	}

	/**
	 * @param ValidatorInterface[] $validators
	 */
	private function validateNullableProperty(
		ValidationResult $result,
		object $object,
		string $propertyName,
		array $validators,
	): void
	{
		$value = $this->getPropertyValue($object, $propertyName);
		if ($value === null)
		{
			return;
		}

		foreach ($validators as $validator)
		{
			$validationResult = $validator->validate($value);
			if ($validationResult->isSuccess())
			{
				continue;
			}

			foreach ($validationResult->getErrors() as $error)
			{
				$result->addError(new ValidationError(
					$error->getLocalizableMessage() ?? $error->getMessage(),
					$propertyName,
				));
			}
		}
	}

	private function getPropertyValue(object $object, string $propertyName): mixed
	{
		$reflection = new \ReflectionClass($object);
		if (!$reflection->hasProperty($propertyName))
		{
			return null;
		}

		$property = $reflection->getProperty($propertyName);
		if (!$property->isInitialized($object))
		{
			return null;
		}

		return $property->getValue($object);
	}
}
