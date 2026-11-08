<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Structure;

final class DetailPageLayoutFieldsStructure extends Structure
{
	private FieldsStructure $fields;

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		self::validateConfiguration($value);
		self::validateAudience($value);

		$structure = new self();
		$structure->fields = FieldsStructure::create(self::restoreOptionsObjects($value), $dtoClass, $request);

		return $structure;
	}

	public function getItems(): array
	{
		return $this->fields->getItems();
	}

	public function convertToDto(mixed $group = null): Dto
	{
		return $this->fields->convertToDto($group);
	}

	private static function validateAudience(mixed $value): void
	{
		if (!is_array($value) || !array_key_exists('audience', $value))
		{
			return;
		}
		if (!is_array($value['audience']) || !array_is_list($value['audience']))
		{
			throw new InvalidRequestFieldTypeException('audience', 'array');
		}

		foreach ($value['audience'] as $index => $item)
		{
			$path = 'audience.' . $index;
			$item = self::validateObject($item, $path);
			self::validateFieldType($item, 'type', $path, 'string');
			self::validateFieldType($item, 'id', $path, 'int');
		}
	}

	private static function validateConfiguration(mixed $value): void
	{
		if (!is_array($value) || !array_key_exists('configuration', $value))
		{
			return;
		}

		if (!is_array($value['configuration']) || !array_is_list($value['configuration']))
		{
			throw new InvalidRequestFieldTypeException('configuration', 'array');
		}

		foreach ($value['configuration'] as $sectionIndex => $section)
		{
			$sectionPath = 'configuration.' . $sectionIndex;
			$section = self::validateObject($section, $sectionPath);
			self::validateFieldType($section, 'name', $sectionPath, 'string');
			self::validateFieldType($section, 'title', $sectionPath, 'string');
			if (!array_key_exists('elements', $section))
			{
				continue;
			}
			if (!is_array($section['elements']) || !array_is_list($section['elements']))
			{
				throw new InvalidRequestFieldTypeException(
					$sectionPath . '.elements',
					'array',
				);
			}

			foreach ($section['elements'] as $elementIndex => $element)
			{
				$elementPath = $sectionPath . '.elements.' . $elementIndex;
				$element = self::validateObject($element, $elementPath);
				self::validateFieldType($element, 'name', $elementPath, 'string');
				self::validateFieldType($element, 'optionFlags', $elementPath, 'int');
				self::validateFieldType($element, 'options', $elementPath, 'object');
			}
		}
	}

	private static function validateObject(mixed $value, string $path): array
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new InvalidRequestFieldTypeException($path, 'object');
		}

		return $value;
	}

	private static function validateFieldType(
		array $value,
		string $fieldName,
		string $parentPath,
		string $expectedType,
	): void
	{
		if (!array_key_exists($fieldName, $value))
		{
			return;
		}

		$fieldValue = $value[$fieldName];
		$isValid = match ($expectedType) {
			'int' => is_int($fieldValue),
			'string' => is_string($fieldValue),
			'object' => is_object($fieldValue)
				|| (is_array($fieldValue) && ($fieldValue === [] || !array_is_list($fieldValue))),
			default => false,
		};
		if (!$isValid)
		{
			throw new InvalidRequestFieldTypeException($parentPath . '.' . $fieldName, $expectedType);
		}
	}

	private static function restoreOptionsObjects(mixed $value): mixed
	{
		if (!is_array($value) || !isset($value['configuration']) || !is_array($value['configuration']))
		{
			return $value;
		}

		foreach ($value['configuration'] as &$section)
		{
			if (!is_array($section) || !isset($section['elements']) || !is_array($section['elements']))
			{
				continue;
			}

			foreach ($section['elements'] as &$element)
			{
				if (is_array($element) && array_key_exists('options', $element) && is_array($element['options']))
				{
					$element['options'] = (object)$element['options'];
				}
			}
			unset($element);
		}
		unset($section);

		return $value;
	}
}
