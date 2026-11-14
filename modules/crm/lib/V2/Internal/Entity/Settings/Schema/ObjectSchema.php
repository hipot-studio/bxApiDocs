<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings\Schema;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectField;
use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectValueInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingErrorCode;
use Bitrix\Crm\V2\Internal\Entity\Settings\Validation\SettingValidationError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * @template TValue of ObjectValueInterface
 * @implements SettingSchemaInterface<TValue>
 * @internal
 */
final class ObjectSchema implements SettingSchemaInterface
{
	/** @var class-string<TValue> */
	private readonly string $valueClass;

	/** @var list<ObjectField> */
	private array $fields;

	/** @param class-string<TValue> $valueClass */
	public function __construct(string $valueClass, ObjectField ...$fields)
	{
		if ($fields === [])
		{
			throw new ArgumentException('Object schema must contain at least one field.');
		}

		$ids = [];
		foreach ($fields as $field)
		{
			if (isset($ids[$field->id]))
			{
				throw new ArgumentException('Object schema field ids must be unique.');
			}

			$ids[$field->id] = true;
		}

		$this->valueClass = $valueClass;
		$this->fields = $fields;
	}

	/** @return list<ObjectField> */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function validate(mixed $value): Result
	{
		$result = new Result();
		$valueClass = $this->valueClass;
		if (!$value instanceof $valueClass)
		{
			return $result->addError(new SettingValidationError(
				'Value must be an instance of the expected object class.',
				SettingErrorCode::INVALID_VALUE_TYPE,
			));
		}

		try
		{
			$value = $value->toArray();
		}
		catch (\TypeError)
		{
			return $result->addError(new SettingValidationError(
				'Value must have a valid associative array representation.',
				SettingErrorCode::INVALID_VALUE_TYPE,
			));
		}

		if ($value !== [] && array_is_list($value))
		{
			return $result->addError(new SettingValidationError(
				'Value must have an associative array representation.',
				SettingErrorCode::INVALID_VALUE_TYPE,
			));
		}

		$fieldsById = [];
		foreach ($this->fields as $field)
		{
			$fieldsById[$field->id] = $field;
		}

		foreach ($value as $id => $_)
		{
			if (!isset($fieldsById[$id]))
			{
				$result->addError(new SettingValidationError(
					'Object contains an unknown field.',
					SettingErrorCode::UNKNOWN_FIELD,
					['path' => (string)$id],
				));
			}
		}

		foreach ($this->fields as $field)
		{
			if (!array_key_exists($field->id, $value))
			{
				if ($field->required)
				{
					$result->addError(new SettingValidationError(
						'Required object field is missing.',
						SettingErrorCode::REQUIRED_FIELD_MISSING,
						['path' => $field->id],
					));
				}

				continue;
			}

			$validationResult = $field->schema->validate($value[$field->id]);
			foreach ($validationResult->getErrors() as $error)
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

				$result->addError(new SettingValidationError(
					$error->getMessage(),
					$error->getCode(),
					$customData,
				));
			}
		}

		return $result;
	}
}
