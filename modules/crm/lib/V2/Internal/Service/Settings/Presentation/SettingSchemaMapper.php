<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Presentation;

use Bitrix\Crm\V2\Internal\Entity\Settings\SettingOption;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\IntegerSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\MultiSelectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\ObjectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\StringSchema;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/** @internal */
final class SettingSchemaMapper
{
	public const ERROR_UNSUPPORTED_SCHEMA = 'UNSUPPORTED_SETTING_SCHEMA';

	public function map(SettingSchemaInterface $schema): Result
	{
		if ($schema instanceof BooleanSchema)
		{
			return $this->createResult([
				'type' => 'boolean',
			]);
		}

		if ($schema instanceof IntegerSchema)
		{
			return $this->createResult([
				'type' => 'integer',
				'min' => $schema->getMin(),
				'max' => $schema->getMax(),
				'step' => $schema->getStep(),
			]);
		}

		if ($schema instanceof StringSchema)
		{
			return $this->createResult([
				'type' => 'string',
				'minLength' => $schema->getMinLength(),
				'maxLength' => $schema->getMaxLength(),
			]);
		}

		if ($schema instanceof SelectSchema)
		{
			return $this->createResult([
				'type' => 'select',
				'options' => $this->mapOptions($schema->getOptions()),
			]);
		}

		if ($schema instanceof MultiSelectSchema)
		{
			return $this->createResult([
				'type' => 'multiSelect',
				'options' => $this->mapOptions($schema->getOptions()),
			]);
		}

		if ($schema instanceof ObjectSchema)
		{
			return $this->mapObjectSchema($schema);
		}

		return (new Result())->addError(
			new Error(
				'Setting schema is not supported.',
				self::ERROR_UNSUPPORTED_SCHEMA,
			),
		);
	}

	private function mapObjectSchema(ObjectSchema $schema): Result
	{
		$result = new Result();
		$fields = [];

		foreach ($schema->getFields() as $field)
		{
			$fieldSchemaResult = $this->map($field->schema);
			if (!$fieldSchemaResult->isSuccess())
			{
				return $result->addErrors($fieldSchemaResult->getErrors());
			}

			$fields[] = [
				'code' => $field->code,
				'title' => $field->title,
				'hint' => $field->hint,
				'required' => $field->required,
				'schema' => $fieldSchemaResult->getData(),
			];
		}

		$result->setData([
			'type' => 'object',
			'fields' => $fields,
		]);

		return $result;
	}

	/**
	 * @param list<SettingOption> $options
	 * @return list<array{value: mixed, title: string, hint: string|null}>
	 */
	private function mapOptions(array $options): array
	{
		$mappedOptions = [];
		foreach ($options as $option)
		{
			$mappedOptions[] = [
				'value' => $option->value,
				'title' => $option->title,
				'hint' => $option->hint,
			];
		}

		return $mappedOptions;
	}

	/** @param array<string, mixed> $data */
	private function createResult(array $data): Result
	{
		$result = new Result();
		$result->setData($data);

		return $result;
	}
}
