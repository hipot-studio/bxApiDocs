<?php

namespace Bitrix\Rest\V3\Dto\Validation;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;

class FieldTypeValidator extends DtoFieldValidator
{
	protected static function isCorrectFieldTypeValue(DtoField $field, mixed $value): bool
	{
		$type = $field->getPropertyType();

		if (!$field->isInitialized() && $value === null)
		{
			return true;
		}

		if ($value === null && $field->isNullable())
		{
			return true;
		}

		$dateTimeClass = strtolower(DateTime::class);
		$dateClass = strtolower(Date::class);

		if (is_subclass_of($field->getPropertyType(), Dto::class))
		{
			return $field->getPropertyType() === get_class($value);
		}

		if ($field->getPropertyType() === DtoCollection::class)
		{
			$elementType = $field->getElementType();
			if ($elementType === null)
			{
				return false;
			}

			foreach ($field->getValue() as $valueItem)
			{
				if (!$valueItem instanceof $elementType)
				{
					return false;
				}
			}

			return true;
		}

		return match (strtolower($type))
		{
			'int', 'integer' => is_int($value),
			'float', 'double' => is_float($value),
			'string' => is_string($value),
			'bool', 'boolean' => is_bool($value),
			'array' => is_array($value),
			'object' => is_object($value),
			'null' => is_null($value),
			$dateTimeClass => $value instanceof DateTime,
			$dateClass => $value instanceof Date,
			default => false,
		};
	}

	/**
	 * @param DtoField $value
	 * @return ValidationResult
	 */
	public function validate(mixed $value): ValidationResult
	{
		$result = new ValidationResult();

		if (!$value instanceof DtoField)
		{
			throw new ArgumentTypeException('value', DtoField::class);
		}

		if (!$value->isInitialized())
		{
			return $result;
		}

		$correct = true;

		if ($value->isMultiple())
		{
			if ($value->getPropertyType() === DtoCollection::class || is_array($value->getValue()))
			{
				foreach ($value->getValue() as $valueItem)
				{
					$correct = self::isCorrectFieldTypeValue($value, $valueItem);
					if (!$correct)
					{
						break;
					}
				}
			}
			else
			{
				$correct = false;
			}
		}
		else
		{
			$correct = self::isCorrectFieldTypeValue($value, $value->getValue());
		}

		if (!$correct)
		{
			$exceptionReflection = new \ReflectionClass(InvalidRequestFieldTypeException::class);

			$propertyType = $value->getPropertyType();

			if (is_object($value->getValue()))
			{
				$typeReflection = new \ReflectionClass($propertyType);
				$propertyType = $typeReflection->getShortName();
			}

			$message = new LocalizableMessage(
				code: 'REST_V3_EXCEPTIONS_VALIDATION_INVALIDREQUESTFIELDTYPEEXCEPTION',
				replace: [
					'#FIELD#' => $value->getPropertyName(),
					'#TYPE#' => $propertyType . ($value->isMultiple() ? '[]' : ''),
				],
				phraseSrcFile: $exceptionReflection->getFileName(),
			);

			$result->addError(new ValidationError($message, $value->getPropertyName()));
		}

		return $result;
	}
}
