<?php

namespace Bitrix\Rest\V3\Dto\Validation;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Rest\Exceptions\ArgumentTypeException;
use Bitrix\Rest\V3\Dto\DtoField;

class FieldRequiredValidator extends DtoFieldValidator
{
	use GroupValidationTrait;

	public function __construct(public readonly string $group)
	{
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

		if ($this->isRequired($value, $this->group) && !$value->isInitialized())
		{
			$result->addError(new ValidationError(
				new LocalizableMessage(
					code: 'REST_V3_DTO_VALIDATION_FIELD_REQUIRED_VALIDATOR_ERROR',
					replace: [
						'#FIELD#' => $value->getPropertyName(),
					],
				),
				$value->getPropertyName(),
			));
		}

		return $result;
	}
}
