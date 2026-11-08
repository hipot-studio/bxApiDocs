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
	public function map(SettingSchemaInterface $schema, string $settingCode, mixed $value): Result
	{
		if ($schema instanceof ObjectSchema)
		{
			return $this->mapObject($schema, $settingCode, $value);
		}

		$result = $schema->validate($value);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->setData(['value' => $value]);

		return $result;
	}

	private function mapObject(ObjectSchema $schema, string $settingCode, mixed $value): Result
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

		$fieldsByCode = [];
		foreach ($schema->getFields() as $field)
		{
			$fieldsByCode[$field->code] = $field;
		}

		foreach ($value as $code => $_)
		{
			if (!isset($fieldsByCode[$code]))
			{
				$result->addError(
					new SettingValidationError(
						'Object contains an unknown field.',
						SettingErrorCode::UNKNOWN_FIELD,
						['path' => (string)$code],
					),
				);
			}
		}

		foreach ($schema->getFields() as $field)
		{
			if (!array_key_exists($field->code, $value))
			{
				$result->addError(
					new SettingValidationError(
						'Required object field is missing.',
						SettingErrorCode::REQUIRED_FIELD_MISSING,
						['path' => $field->code],
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
			$fieldResult = $this->map($field->schema, $settingCode, $value[$field->code]);
			if (!$fieldResult->isSuccess())
			{
				$this->addFieldErrors($result, $field, $fieldResult);

				continue;
			}

			$mappedFields[$field->code] = $fieldResult->getData()['value'];
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		$mappedValue = $this->createObjectValue($settingCode, $mappedFields, $result);
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
			$customData['path'] = $field->code . ($path === null ? '' : '.' . $path);
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
	private function createObjectValue(string $settingCode, array $fields, Result $result): mixed
	{
		if ($settingCode !== CallToSetting::CODE)
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
