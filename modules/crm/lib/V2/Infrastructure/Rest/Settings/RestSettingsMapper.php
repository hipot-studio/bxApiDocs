<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectValueInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\BooleanSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\IntegerSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\MultiSelectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\ObjectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SelectSchema;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\SettingSchemaInterface;
use Bitrix\Crm\V2\Internal\Entity\Settings\Schema\StringSchema;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

final class RestSettingsMapper
{
	public const ERROR_MAPPING_FAILED = 'REST_SETTING_MAPPING_FAILED';
	public const ERROR_UNSUPPORTED_VALUE = 'UNSUPPORTED_SETTING_VALUE';

	public function map(SettingInterface $setting): Result
	{
		try
		{
			$description = $setting->getDescription();
			$schema = $this->mapSchema($description->schema);
			$value = $setting->getValue();
			if (is_object($value))
			{
				if (!$value instanceof ObjectValueInterface)
				{
					return (new Result())->addError(new Error(
						'Setting value object cannot be serialized.',
						self::ERROR_UNSUPPORTED_VALUE,
					));
				}

				$value = $value->toArray();
			}

			return (new Result())->setData([
				'id' => $setting->getId(),
				'value' => $value,
				'meta' => [
					'title' => $description->title,
					'description' => $description->description ?? '',
					'schema' => $schema,
				],
			]);
		}
		catch (Throwable)
		{
			return (new Result())->addError(new Error(
				'Failed to map the setting.',
				self::ERROR_MAPPING_FAILED,
			));
		}
	}

	private function mapSchema(SettingSchemaInterface $schema): array
	{
		if ($schema instanceof BooleanSchema)
		{
			return ['type' => 'boolean'];
		}

		if ($schema instanceof IntegerSchema)
		{
			return [
				'type' => 'integer',
				'min' => $schema->getMin(),
				'max' => $schema->getMax(),
				'step' => $schema->getStep(),
			];
		}

		if ($schema instanceof StringSchema)
		{
			return [
				'type' => 'string',
				'minLength' => $schema->getMinLength(),
				'maxLength' => $schema->getMaxLength(),
			];
		}

		if ($schema instanceof SelectSchema || $schema instanceof MultiSelectSchema)
		{
			$options = [];
			foreach ($schema->getOptions() as $option)
			{
				$options[] = [
					'value' => $option->value,
					'title' => $option->title,
					'description' => $option->description ?? '',
				];
			}

			return [
				'type' => $schema instanceof SelectSchema ? 'select' : 'multiSelect',
				'options' => $options,
			];
		}

		if ($schema instanceof ObjectSchema)
		{
			$fields = [];
			foreach ($schema->getFields() as $field)
			{
				$fields[] = [
					'id' => $field->id,
					'title' => $field->title,
					'description' => $field->description ?? '',
					'required' => $field->required,
					'schema' => $this->mapSchema($field->schema),
				];
			}

			return ['type' => 'object', 'fields' => $fields];
		}

		throw new \LogicException('Setting schema is not supported.');
	}
}
