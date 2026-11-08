<?php

namespace Bitrix\Crm\Integration\BizProc\Product;

use Bitrix\Bizproc\FieldType;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;

final class PropertyTypeMapper
{
	private const FIELD_TYPES = [
		PropertyTable::TYPE_STRING => [
			'' => FieldType::STRING,
			PropertyTable::USER_TYPE_DATE => FieldType::DATE,
			PropertyTable::USER_TYPE_DATETIME => FieldType::DATETIME,
			PropertyTable::USER_TYPE_USER => FieldType::USER,
			PropertyTable::USER_TYPE_EMPLOYEE => FieldType::USER,
			PropertyTable::USER_TYPE_DIRECTORY => FieldType::STRING,
		],
		PropertyTable::TYPE_NUMBER => [
			'' => FieldType::DOUBLE,
			PropertyTable::USER_TYPE_USER => FieldType::USER,
		],
		PropertyTable::TYPE_LIST => [
			'' => FieldType::SELECT,
		],
		PropertyTable::TYPE_FILE => [
			'' => FieldType::FILE,
		],
		PropertyTable::TYPE_ELEMENT => [
			'' => FieldType::INT,
			PropertyTable::USER_TYPE_ELEMENT_LIST => FieldType::INT,
			PropertyTable::USER_TYPE_ELEMENT_AUTOCOMPLETE => FieldType::INT,
		],
	];

	public function toFieldProperty(array $iblockProperty): ?array
	{
		$type = $this->resolveFieldType($iblockProperty);
		if ($type === null)
		{
			return null;
		}

		$field = [
			'Name' => (string)$iblockProperty['NAME'],
			'Type' => $type,
			'Multiple' => $this->isMultiple($iblockProperty),
		];
		if ($type === FieldType::SELECT)
		{
			$field['Options'] = [];
		}

		return $field;
	}

	public function toValue(array $iblockProperty, mixed $rawValue): mixed
	{
		$type = $this->resolveFieldType($iblockProperty);
		if ($type === null)
		{
			return null;
		}

		if (!$this->isMultiple($iblockProperty))
		{
			$value = is_array($rawValue) ? reset($rawValue) : $rawValue;

			return \CBPHelper::isEmptyValue($value) ? null : $this->convert($iblockProperty, $type, $value);
		}

		$values = [];
		foreach ((array)$rawValue as $value)
		{
			if (!\CBPHelper::isEmptyValue($value))
			{
				$values[] = $this->convert($iblockProperty, $type, $value);
			}
		}

		return $values ?: null;
	}

	private function resolveFieldType(array $iblockProperty): ?string
	{
		$userType = (string)($iblockProperty['USER_TYPE'] ?? '');
		if ($userType === PropertyTable::USER_TYPE_DIRECTORY && !Loader::includeModule('highloadblock'))
		{
			return null;
		}

		return self::FIELD_TYPES[$iblockProperty['PROPERTY_TYPE'] ?? ''][$userType] ?? null;
	}

	private function isMultiple(array $iblockProperty): bool
	{
		return ($iblockProperty['MULTIPLE'] ?? 'N') === 'Y';
	}

	private function convert(array $property, string $type, mixed $value): mixed
	{
		return match ($type)
		{
			FieldType::DOUBLE => (float)$value,
			FieldType::FILE, FieldType::INT => (int)$value,
			FieldType::USER => 'user_' . (int)$value,
			FieldType::DATE => \CIBlockPropertyDate::ConvertFromDB($property, ['VALUE' => $value])['VALUE'],
			FieldType::DATETIME => \CIBlockPropertyDateTime::ConvertFromDB($property, ['VALUE' => $value])['VALUE'],
			default => (string)$value,
		};
	}
}
