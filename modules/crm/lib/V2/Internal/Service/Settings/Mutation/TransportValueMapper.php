<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Mutation;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectField;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\ObjectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\Setting\Format\CallToSetting;
use Bitrix\Crm\V2\Internal\Entity\Settings\Value\CallToValue;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingValidationError;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class TransportValueMapper
{
	public function map(SettingSchemaInterface $schema, string $settingId, mixed $value): Result
	{
		if ($schema instanceof ObjectSchema)
		{
			return $this->mapObject($schema, $settingId, $value);
		}

		$result = $schema->validate($value);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->setData(['value' => $value]);

		return $result;
	}

	private function mapObject(ObjectSchema $schema, string $settingId, mixed $value): Result
	{
		$result = new Result();
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			return $result->addError(
				new SettingValidationError(
					'Value must be an object.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);
		}

		$fieldsById = [];
		foreach ($schema->getFields() as $field)
		{
			$fieldsById[$field->id] = $field;
		}

		foreach ($value as $id => $_)
		{
			if (!isset($fieldsById[$id]))
			{
				$result->addError(
					new SettingValidationError(
						'Object contains an unknown field.',
						SettingErrorCode::UNKNOWN_FIELD,
						['path' => (string)$id],
					),
				);
			}
		}

		foreach ($schema->getFields() as $field)
		{
			if (!array_key_exists($field->id, $value))
			{
				$result->addError(
					new SettingValidationError(
						'Required object field is missing.',
						SettingErrorCode::REQUIRED_FIELD_MISSING,
						['path' => $field->id],
					),
				);
			}
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$mappedFields = [];
		foreach ($schema->getFields() as $field)
		{
			$fieldResult = $this->map($field->schema, $settingId, $value[$field->id]);
			if (!$fieldResult->isSuccess())
			{
				$this->addFieldErrors($result, $field, $fieldResult);

				continue;
			}

			$mappedFields[$field->id] = $fieldResult->getData()['value'];
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$mappedValue = $this->createObjectValue($settingId, $mappedFields, $result);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$validationResult = $schema->validate($mappedValue);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$result->setData(['value' => $mappedValue]);

		return $result;
	}

	private function addFieldErrors(Result $result, ObjectField $field, Result $fieldResult): void
	{
		foreach ($fieldResult->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if ($customData === null)
			{
				$customData = [];
			}
			elseif (!is_array($customData))
			{
				$customData = ['originalCustomData' => $customData];
			}

			$path = $customData['path'] ?? null;
			$customData['path'] = $field->id . ($path === null ? '' : '.' . $path);
			$result->addError(
				new SettingValidationError(
					$error->getMessage(),
					$error->getCode(),
					$customData,
				),
			);
		}
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function createObjectValue(string $settingId, array $fields, Result $result): mixed
	{
		if ($settingId !== CallToSetting::ID)
		{
			$result->addError(
				new Error(
					'Object transport mapping is not supported for this setting.',
					'INVALID_ARG_VALUE',
				),
			);

			return null;
		}

		try
		{
			return new CallToValue(
				$fields['format'],
				$fields['urlTemplate'],
				$fields['clickHandler'],
				$fields['normalizeNumber'],
			);
		}
		catch (\TypeError)
		{
			$result->addError(
				new SettingValidationError(
					'Object contains a field with an invalid value type.',
					SettingErrorCode::INVALID_VALUE_TYPE,
				),
			);

			return null;
		}
	}
}
