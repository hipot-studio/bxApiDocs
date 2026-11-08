<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\Main\Web\Json;
use Throwable;

class RequisitePresetMapping
{
	private const COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'COUNTRY_ID',
		'DATE_CREATE',
		'DATE_MODIFY',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'NAME',
		'XML_ID',
		'ACTIVE',
		'SORT',
		'SETTINGS',
	];

	private const INTEGER_COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'COUNTRY_ID',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'SORT',
	];

	private const DATETIME_COLUMNS = [
		'DATE_CREATE',
		'DATE_MODIFY',
	];

	private const MAX_SETTINGS_DEPTH = 64;
	private const MAX_SETTINGS_ELEMENTS = 10000;

	public static function getMapping(): array
	{
		$fields = [];
		foreach (self::COLUMNS as $column)
		{
			$field = [
				'IS_METRIC' => 'N',
				'FIELD_NAME' => 'P.' . $column,
				'FIELD_TYPE' => self::getFieldType($column),
			];
			if ($column === 'ID')
			{
				$field['IS_PRIMARY'] = 'Y';
			}
			if ($column === 'SETTINGS')
			{
				$field['CALLBACK'] = static fn(?string $value, array $dateFormats): string => self::convertSettings($value);
			}

			$fields[$column] = $field;
		}

		return [
			'TABLE_NAME' => 'b_crm_preset',
			'TABLE_ALIAS' => 'P',
			'FIELDS' => $fields,
		];
	}

	private static function getFieldType(string $column): string
	{
		if (in_array($column, self::INTEGER_COLUMNS, true))
		{
			return 'int';
		}

		if (in_array($column, self::DATETIME_COLUMNS, true))
		{
			return 'datetime';
		}

		return 'string';
	}

	private static function convertSettings(?string $value): string
	{
		if ($value === null || $value === '')
		{
			return '{}';
		}

		try
		{
			$settings = @unserialize($value, ['allowed_classes' => false]);
			$elementCount = 0;
			if (!is_array($settings) || !self::isValidSettingsValue($settings, 0, $elementCount))
			{
				return 'null';
			}

			return Json::encode($settings === [] ? new \stdClass() : $settings, JSON_THROW_ON_ERROR);
		}
		catch (Throwable)
		{
			return 'null';
		}
	}

	private static function isValidSettingsValue(mixed $value, int $depth, int &$elementCount): bool
	{
		if (!is_array($value))
		{
			return is_scalar($value) || $value === null;
		}

		if ($depth > self::MAX_SETTINGS_DEPTH)
		{
			return false;
		}

		foreach ($value as $item)
		{
			$elementCount++;
			if (
				$elementCount > self::MAX_SETTINGS_ELEMENTS
				|| !self::isValidSettingsValue($item, $depth + 1, $elementCount)
			)
			{
				return false;
			}
		}

		return true;
	}
}
