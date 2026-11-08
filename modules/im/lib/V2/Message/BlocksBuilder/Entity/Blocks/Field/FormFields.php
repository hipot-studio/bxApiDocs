<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;
use Bitrix\Im\V2\Result;

class FormFields extends AbstractField
{
	public function validate(mixed $field, ?BlockType $blockType = null): Result
	{
		$result = new Result();

		if (!is_array($field))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_ELEMENTS_FIELD));
		}

		if (empty($field))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_FORM_FIELDS));
		}

		$fieldsResult = [];
		foreach ($field as $item)
		{
			if (!is_array($item))
			{
				return $result->addError(new BuilderError(BuilderError::INVALID_ELEMENTS_FIELD));
			}

			$result = $this->validateOneField($item);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$fieldsResult[] = $result->getResult();
		}

		return $result->setResult($fieldsResult);
	}

	protected function validateOneField(array $field): Result
	{
		$fieldType = Type::tryFrom((string)($field['type'] ?? ''));
		if ($fieldType === null)
		{
			return (new Result())->addError(new BuilderError(BuilderError::WRONG_FORM_FIELD_TYPE));
		}

		$result = $this->validateLabel($field);
		if (!$result->isSuccess())
		{
			return $result;
		}
		$label = $result->getResult();

		$validated = [
			'type' => $fieldType->value,
			'label' => $label,
			'disabled' => self::validateBoolValue($field['disabled'] ?? false),
		];

		if (isset($field['id']))
		{
			$validated['id'] = (string)$field['id'];
		}

		return match ($fieldType)
		{
			Type::SingleLineText, Type::MultilineText => $this->validateTextField($field, $validated),
			Type::Select, Type::Radio, Type::Checkbox => $this->validateOptionsField($field, $validated),
		};
	}

	protected function validateTextField(array $field, array $validated): Result
	{
		$result = new Result();

		$placeholder = $field['placeholder'] ?? null;
		if ($placeholder !== null && !is_string($placeholder))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_PLACEHOLDER_FIELD));
		}

		$validated['placeholder'] = $placeholder;

		return $result->setResult($validated);
	}

	protected function validateOptionsField(array $field, array $validated): Result
	{
		$result = new Result();

		$options = $field['options'] ?? null;
		if (!is_array($options))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_OPTIONS_FIELD));
		}
		if (empty($options))
		{
			return $result->addError(new BuilderError(BuilderError::EMPTY_OPTIONS_FIELD));
		}

		$validatedOptions = [];
		$seenIds = [];
		foreach ($options as $option)
		{
			$result = $this->validateOption($option);
			if (!$result->isSuccess())
			{
				return $result;
			}

			$validatedOption = $result->getResult();

			// Option id must be unique within the field — the bot addresses an option by its id.
			if (isset($seenIds[$validatedOption['id']]))
			{
				return (new Result())->addError(new BuilderError(BuilderError::INVALID_OPTIONS_FIELD));
			}
			$seenIds[$validatedOption['id']] = true;

			$validatedOptions[] = $validatedOption;
		}

		$validated['options'] = $validatedOptions;

		return $result->setResult($validated);
	}

	protected function validateOption(mixed $option): Result
	{
		$result = new Result();

		if (!is_array($option))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_OPTIONS_FIELD));
		}

		$label = $option['label'] ?? null;
		if (!is_string($label) || $label === '')
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_OPTIONS_FIELD));
		}

		// The id is a required, meaningful key set by the bot — never auto-generated here.
		$id = (string)($option['id'] ?? '');
		if ($id === '')
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_OPTIONS_FIELD));
		}

		return $result->setResult([
			'id' => $id,
			'label' => $label,
		]);
	}

	protected function validateLabel(array $field): Result
	{
		$result = new Result();

		$label = $field['label'] ?? null;
		if ($label !== null && !is_string($label))
		{
			return $result->addError(new BuilderError(BuilderError::INVALID_LABEL_FIELD));
		}

		return $result->setResult($label);
	}
}
