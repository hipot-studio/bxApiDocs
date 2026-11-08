<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Validation;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\CheckboxField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\FormField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\Option;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\RadioField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\SelectField;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\FormSubmitError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Web\Json;

class FormSubmitValidationService
{
	public function validate(FormBlock $block, array $values): Result
	{
		$result = new Result();

		if (mb_strlen(Json::encode($values)) >= BuilderService::MAX_JSON_LENGTH)
		{
			return $result->addError(new FormSubmitError(FormSubmitError::VALUES_LENGTH_EXCEEDED));
		}

		$fieldsById = [];
		foreach ($block->getElements() as $field)
		{
			/** @var FormField $field */
			$fieldsById[$field->getId()] = $field;
		}

		$validated = [];
		foreach ($values as $fieldId => $value)
		{
			$field = $fieldsById[$fieldId] ?? null;
			if ($field === null)
			{
				return $result->addError(new FormSubmitError(FormSubmitError::UNKNOWN_FIELD_ID));
			}

			if ($field->isDisabled())
			{
				return $result->addError(new FormSubmitError(FormSubmitError::FIELD_DISABLED));
			}

			$fieldResult = $this->validateValue($field, $value);
			if (!$fieldResult->isSuccess())
			{
				return $fieldResult;
			}

			$validated[$fieldId] = $fieldResult->getResult();
		}

		return $result->setResult($validated);
	}

	protected function validateValue(FormField $field, mixed $value): Result
	{
		$type = Type::tryFrom($field->getType());

		return match ($type)
		{
			Type::SingleLineText, Type::MultilineText => $this->validateTextValue($value),
			Type::Select, Type::Radio => $this->validateSingleOptionValue($field, $value),
			Type::Checkbox => $this->validateMultipleOptionValue($field, $value),
			default => (new Result())->addError(new FormSubmitError(FormSubmitError::INVALID_VALUE_TYPE)),
		};
	}

	protected function validateTextValue(mixed $value): Result
	{
		$result = new Result();

		if (!is_string($value))
		{
			return $result->addError(new FormSubmitError(FormSubmitError::INVALID_VALUE_TYPE));
		}

		return $result->setResult($value);
	}

	protected function validateSingleOptionValue(FormField $field, mixed $value): Result
	{
		$result = new Result();

		if (!is_string($value))
		{
			return $result->addError(new FormSubmitError(FormSubmitError::INVALID_VALUE_TYPE));
		}

		/** @var SelectField|RadioField $field */
		if (!$this->optionExists($field->getOptions(), $value))
		{
			return $result->addError(new FormSubmitError(FormSubmitError::UNKNOWN_OPTION_ID));
		}

		return $result->setResult($value);
	}

	protected function validateMultipleOptionValue(FormField $field, mixed $value): Result
	{
		$result = new Result();

		// checkbox value must be a plain list of option ids (DTO-02): a JSON object
		// decodes into an associative array that would slip past a bare is_array() check
		if (!is_array($value) || !array_is_list($value))
		{
			return $result->addError(new FormSubmitError(FormSubmitError::INVALID_VALUE_TYPE));
		}

		/** @var CheckboxField $field */
		$optionIds = [];
		foreach ($field->getOptions() as $option)
		{
			$optionIds[$option->getId()] = true;
		}

		foreach ($value as $optionId)
		{
			if (!is_string($optionId))
			{
				return $result->addError(new FormSubmitError(FormSubmitError::INVALID_VALUE_TYPE));
			}

			if (!isset($optionIds[$optionId]))
			{
				return $result->addError(new FormSubmitError(FormSubmitError::UNKNOWN_OPTION_ID));
			}
		}

		return $result->setResult($value);
	}

	/** @param Option[] $options */
	protected function optionExists(array $options, string $optionId): bool
	{
		foreach ($options as $option)
		{
			if ($option->getId() === $optionId)
			{
				return true;
			}
		}

		return false;
	}
}
