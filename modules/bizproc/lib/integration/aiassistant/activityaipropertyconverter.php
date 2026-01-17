<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Bizproc\BaseType\Select;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOption;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;

class ActivityAiPropertyConverter
{
	public const PROPERTY_FIELD_AI_DESCRIPTION = 'AiDescription';
	public const PROPERTY_FIELD_MAP = 'Map';
	public const PROPERTY_FIELD_TYPE = 'Type';
	public const SETTING_TYPE_MAP = 'map';
	public const UF_TYPE_PREFIX = 'uf:';
	private const DEFAULT_MAP_DESCRIPTION = 'Fields container';

	public function convertMap(array $map, array $documentType): SettingCollection
	{
		$settings = new SettingCollection();
		foreach ($map as $name => $field)
		{
			$setting = $this->makeSetting($name, $field, $documentType);
			if ($setting !== null)
			{
				$settings->add($setting);
			}
		}

		return $settings;
	}

	private function makeSettingOptions(FieldType $field): ?SettingOptionCollection
	{
		if (is_subclass_of($field->getTypeClass(), Select::class))
		{
			$properties = $field
				->getTypeClass()::convertPropertyToView($field, FieldType::RENDER_MODE_JN_MOBILE, [])
			;
			if (!empty($properties['Options']) && is_array($properties['Options']))
			{
				return $this->convertJNMobileOptions($properties['Options']);
			}
		}

		if (empty($field->getOptions()) || !is_array($field->getOptions()))
		{
			return null;
		}

		$options = new SettingOptionCollection();
		foreach ($field->getOptions() as $id => $name)
		{
			$options->add(
				new SettingOption(
					id: (string)$id,
					name: (string)$name,
				)
			);
		}

		return $options;
	}

	private function getFieldDescription(array $field, FieldType $fieldType): ?string
	{
		$description = $field[self::PROPERTY_FIELD_AI_DESCRIPTION] ?? null;
		if (!empty($description) && is_string($description))
		{
			return $description;
		}

		if (!empty($fieldType->getDescription()))
		{
			return $fieldType->getDescription();
		}

		return $fieldType->getName();
	}

	private function makeSetting(string $name, array $field, array $documentType): ?Setting
	{
		if (!empty($field[static::PROPERTY_FIELD_MAP]) && is_array($field[static::PROPERTY_FIELD_MAP]))
		{
			return $this->makeMapSetting($name, $field, $documentType);
		}

		return $this->makeFieldSetting($name, $field, $documentType);
	}

	private function makeFieldSetting(string $name, array $field, array $documentType): ?Setting
	{
		$fieldType = $this->getFieldType($field, $documentType);
		if ($fieldType === null || empty($fieldType->getBaseType()))
		{
			return $this->getUserFieldSetting($name, $field);
		}

		$description = $this->getFieldDescription($field, $fieldType);
		if (empty($description))
		{
			return null;
		}

		return new Setting(
			name: $name,
			description: $description,
			type: $this->getSettingType($fieldType),
			required: $fieldType->isRequired(),
			multiple: $fieldType->isMultiple(),
			options: $this->makeSettingOptions($fieldType),
			defaultValue: $fieldType->getValue(),
		);
	}

	private function makeMapSetting(string $name, array $field, array $documentType): ?Setting
	{
		$children = $this->convertMap($field[static::PROPERTY_FIELD_MAP], $documentType);
		if ($children->getIterator()->count() < 1)
		{
			return null;
		}

		return new Setting(
			name: $name,
			description: $field[static::PROPERTY_FIELD_AI_DESCRIPTION] ?? static::DEFAULT_MAP_DESCRIPTION,
			type: static::SETTING_TYPE_MAP,
			children: $children,
		);
	}

	public function getFieldType(array $field, array $documentType): ?FieldType
	{
		/** @var \CBPDocumentService $documentService */
		$documentService = \CBPRuntime::getRuntime()->getService('DocumentService');

		$field = FieldType::normalizeProperty($field);

		$propertyFieldType = $field[static::PROPERTY_FIELD_TYPE] ?? null;
		$typeClass = $documentService->getTypeClass($documentType, $propertyFieldType);
		if ($typeClass && class_exists($typeClass))
		{
			return new FieldType($field, $documentType, $typeClass);
		}

		return null;
	}

	private function convertJNMobileOptions(array $options): SettingOptionCollection
	{
		$collection = new SettingOptionCollection();
		foreach ($options as $option)
		{
			if (!isset($option['name']) || !isset($option['value']))
			{
				continue;
			}

			$collection->add(
				new SettingOption(
					id: (string)$option['value'],
					name: (string)$option['name'],
				)
			);
		}

		return $collection;
	}

	public function getSettingType(FieldType $fieldType): SettingType|string
	{
		if (class_exists($fieldType->getTypeClass())
			&& $fieldType->getTypeClass()::getAiSettingType() instanceof SettingType
		)
		{
			return $fieldType->getTypeClass()::getAiSettingType();
		}

		return $fieldType->getBaseType();
	}

	private function getUserFieldSetting(string $name, array $field): ?Setting
	{
		$description = $this->getDescriptionFromArrayField($field);
		if (empty($description))
		{
			return null;
		}

		$type = (string)($field['Type'] ?? '');
		if (self::isUfPrefixed($type))
		{
			$type = $this->removeUfPrefix($type);
		}

		return match ($type)
		{
			'disk_file' => $this->makeDiskFilesSetting($name, $description, $type, $field),
			default => null,
		};
	}

	public static function isUfPrefixed(string $type): bool
	{
		return str_starts_with($type, self::UF_TYPE_PREFIX);
	}

	private function getDescriptionFromArrayField(array $field): ?string
	{
		return $field[static::PROPERTY_FIELD_AI_DESCRIPTION]
			?? $field['Description']
			?? $field['Name']
			?? null
		;
	}

	private function removeUfPrefix(string $type): string
	{
		return mb_substr($type, mb_strlen(self::UF_TYPE_PREFIX));
	}

	private function makeDiskFilesSetting(
		string $name,
		string $description,
		string $typeWithoutPrefix,
		array $field
	): Setting
	{
		return new Setting(
			name: $name,
			description: $description,
			type: new SettingType(
				name: $typeWithoutPrefix,
				description: 'The values are references to Bitrix Disk objects and have the form "n_{number}", where {number} is the numeric identifier of the disk object in Bitrix24, for example "n_1" for the disk object with ID 1',
			),
			required: (bool)($field['Required'] ?? false),
			multiple: (bool)($field['Multiple'] ?? false),
		);
	}
}