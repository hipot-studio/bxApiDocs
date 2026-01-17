<?php

namespace Bitrix\Rest\V3\Dto;

use Bitrix\Main\Validation\Rule\ClassValidationAttributeInterface;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\V3\Dto\Validation\FieldRequiredValidator;
use Bitrix\Rest\V3\Dto\Validation\FieldTypeValidator;

class DtoValidatorHelper
{
	public static function validate(Dto $dto, string $group = 'default'): ValidationResult
	{
		$result = new ValidationResult();
		$validateRequired = new FieldRequiredValidator($group);
		$validateType = new FieldTypeValidator();
		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			if ($field->getType() === DtoField::DTO_FIELD_TYPE_PROPERTY)
			{
				$property = PropertyHelper::getProperty($dto, $field->getPropertyName());
				if ($property->isInitialized($dto))
				{
					$field->setValue($dto->{$property->getName()});
				}
			}

			$validateRequiredResult = $validateRequired->validate($field);
			if (!$validateRequiredResult->isSuccess())
			{
				$result->addErrors($validateRequiredResult->getErrors());

				continue;
			}

			if (!$field->isInitialized())
			{
				continue;
			}

			$validateTypeResult = $validateType->validate($field);
			if (!$validateTypeResult->isSuccess())
			{
				$result->addErrors($validateTypeResult->getErrors());

				continue;
			}

			// validate rules
			foreach ($field->getValidationRules() as $rule)
			{
				$ruleResult = $rule->validateProperty($field->getValue());
				if (!$ruleResult->isSuccess())
				{
					foreach ($ruleResult->getErrors() as $ruleError)
					{
						$result->addError(new ValidationError($ruleError->getLocalizableMessage(), $field->getPropertyName()));
					}
				}
			}

			if ($field->getPropertyType() === DtoCollection::class)
			{
				foreach ($field->getValue() as $collectionIndex => $collectionItem)
				{
					$collectionItemResult = self::validate($collectionItem, $group);
					if (!$collectionItemResult->isSuccess())
					{
						foreach ($collectionItemResult->getErrors() as $collectionItemError)
						{
							$collectionItemErrorCode = $field->getPropertyName()
								. '.' . $collectionIndex
								. (is_string($collectionItemError->getCode()) ? '.' . $collectionItemError->getCode() : ''); // prepend field name to error code

							$result->addError(new ValidationError($collectionItemError->getLocalizableMessage(), $collectionItemErrorCode));
						}
					}
				}
				continue;
			}

			if (is_subclass_of($field->getPropertyType(), Dto::class))
			{
				$dtoResult = self::validate($field->getValue(), $group);
				if (!$dtoResult->isSuccess())
				{
					foreach ($dtoResult->getErrors() as $dtoError)
					{
						$dtoErrorCode = $field->getPropertyName()
							. (is_string($dtoError->getCode()) ? '.' . $dtoError->getCode() : ''); // prepend field name to error code
						$result->addError(new ValidationError($dtoError->getLocalizableMessage(), $dtoErrorCode));
					}
				}
				continue;
			}
		}

		foreach ($dto->getAttributes() as $attribute)
		{
			if ($attribute instanceof ClassValidationAttributeInterface)
			{
				$attributeValidationResult = $attribute->validateObject($dto);
				if (!$attributeValidationResult->isSuccess())
				{
					$result->addErrors($attributeValidationResult->getErrors());
				}
			}
		}

		return $result;
	}
}
