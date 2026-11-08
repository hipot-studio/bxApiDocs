<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Layout;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class LayoutConfiguration
{
	private const SECTION_FIELDS = ['name', 'title', 'elements'];
	private const ELEMENT_FIELDS = ['name', 'optionFlags', 'options'];
	private const STORED_COLUMN_FIELDS = ['name', 'type', 'data', 'elements'];
	private const STORED_SECTION_FIELDS = ['name', 'title', 'type', 'data', 'elements'];
	private const STORED_ELEMENT_FIELDS = ['name', 'title', 'optionFlags', 'options'];

	/**
	 * @param array<int, array{
	 *     name: string,
	 *     title: string,
	 *     elements: array<int, array{name: string, optionFlags?: int, options?: array<string, mixed>}>
	 * }> $sections
	 */
	private function __construct(private readonly array $sections)
	{
	}

	public static function create(array $sections): Result
	{
		if ($sections === [])
		{
			return self::invalidConfigurationResult();
		}

		return self::createFromSections($sections);
	}

	/**
	 * Restores a configuration received by a provider from persistent storage.
	 *
	 * @param array<int, array{
	 *     name: string,
	 *     title: string,
	 *     elements: array<int, array{name: string, optionFlags?: int|'0'|'1', options?: array<string, mixed>}>
	 * }> $sections
	 */
	public static function restore(array $sections): Result
	{
		$adaptedSections = self::adaptStoredSections($sections);
		if ($adaptedSections === null)
		{
			return self::invalidConfigurationResult();
		}

		return self::createFromSections($adaptedSections, true);
	}

	private static function createFromSections(array $sections, bool $allowLegacyOptionFlags = false): Result
	{
		$normalizedSections = self::normalizeSections($sections, $allowLegacyOptionFlags);
		if ($normalizedSections === null)
		{
			return self::invalidConfigurationResult();
		}

		return (new Result())->setData([
			'configuration' => new self($normalizedSections),
		]);
	}

	private static function invalidConfigurationResult(): Result
	{
		return (new Result())->addError(
			new Error('The layout configuration is invalid.', 'INVALID_LAYOUT_CONFIGURATION'),
		);
	}

	/**
	 * @return array<int, array{
	 *     name: string,
	 *     title: string,
	 *     elements: array<int, array{name: string, optionFlags?: int, options?: array<string, mixed>}>
	 * }>
	 */
	public function getSections(): array
	{
		return $this->sections;
	}

	/**
	 * @return array<int, array{
	 *     name: string,
	 *     title: string,
	 *     elements: array<int, array{name: string, optionFlags?: int, options?: array<string, mixed>}>
	 * }>
	 */
	public function toArray(): array
	{
		return $this->sections;
	}

	private static function normalizeSections(array $sections, bool $allowLegacyOptionFlags): ?array
	{
		if (!array_is_list($sections))
		{
			return null;
		}

		$normalizedSections = [];
		foreach ($sections as $section)
		{
			$normalizedSection = self::normalizeSection($section, $allowLegacyOptionFlags);
			if ($normalizedSection === null)
			{
				return null;
			}

			$normalizedSections[] = $normalizedSection;
		}

		return $normalizedSections;
	}

	private static function adaptStoredSections(array $layout): ?array
	{
		if (!array_is_list($layout))
		{
			return null;
		}

		if ($layout !== [] && is_array($layout[0]) && ($layout[0]['type'] ?? null) === 'column')
		{
			$sections = [];
			foreach ($layout as $column)
			{
				if (
					!is_array($column)
					|| !self::hasOnlyFields($column, self::STORED_COLUMN_FIELDS)
					|| ($column['type'] ?? null) !== 'column'
					|| !self::isNonEmptyString($column['name'] ?? null)
					|| (
						array_key_exists('elements', $column)
						&& (!is_array($column['elements']) || !array_is_list($column['elements']))
					)
					|| (array_key_exists('data', $column) && !is_array($column['data']))
				)
				{
					return null;
				}

				$adaptedSections = self::adaptSectionItems($column['elements'] ?? []);
				if ($adaptedSections === null)
				{
					return null;
				}

				array_push($sections, ...$adaptedSections);
			}

			return $sections;
		}

		return self::adaptSectionItems($layout);
	}

	private static function adaptSectionItems(array $sections): ?array
	{
		$adaptedSections = [];
		foreach ($sections as $section)
		{
			if (
				!is_array($section)
				|| !self::hasOnlyFields($section, self::STORED_SECTION_FIELDS)
				|| (array_key_exists('type', $section) && $section['type'] !== 'section')
				|| (array_key_exists('data', $section) && !is_array($section['data']))
				|| (
					array_key_exists('elements', $section)
					&& (!is_array($section['elements']) || !array_is_list($section['elements']))
				)
			)
			{
				return null;
			}

			$elements = [];
			foreach ($section['elements'] ?? [] as $element)
			{
				if (
					!is_array($element)
					|| !self::hasOnlyFields($element, self::STORED_ELEMENT_FIELDS)
					|| (array_key_exists('title', $element) && !is_string($element['title']))
				)
				{
					return null;
				}

				unset($element['title']);
				$elements[] = $element;
			}

			unset($section['type'], $section['data']);
			$section['elements'] = $elements;
			$adaptedSections[] = $section;
		}

		return $adaptedSections;
	}

	private static function normalizeSection(mixed $section, bool $allowLegacyOptionFlags): ?array
	{
		if (
			!is_array($section)
			|| !self::hasOnlyFields($section, self::SECTION_FIELDS)
			|| !self::isNonEmptyString($section['name'] ?? null)
			|| !array_key_exists('elements', $section)
			|| !is_array($section['elements'])
			|| !array_is_list($section['elements'])
		)
		{
			return null;
		}

		$title = $section['title'] ?? $section['name'];
		if (!is_string($title))
		{
			return null;
		}

		$elements = [];
		foreach ($section['elements'] as $element)
		{
			$normalizedElement = self::normalizeElement($element, $allowLegacyOptionFlags);
			if ($normalizedElement === null)
			{
				return null;
			}

			$elements[] = $normalizedElement;
		}

		return [
			'name' => $section['name'],
			'title' => $title,
			'elements' => $elements,
		];
	}

	private static function normalizeElement(mixed $element, bool $allowLegacyOptionFlags): ?array
	{
		if (
			!is_array($element)
			|| !self::hasOnlyFields($element, self::ELEMENT_FIELDS)
			|| !self::isNonEmptyString($element['name'] ?? null)
		)
		{
			return null;
		}

		$normalizedElement = ['name' => $element['name']];
		if (array_key_exists('optionFlags', $element))
		{
			$optionFlags = $element['optionFlags'];
			if ($allowLegacyOptionFlags && ($optionFlags === '0' || $optionFlags === '1'))
			{
				$optionFlags = (int)$optionFlags;
			}
			if (!is_int($optionFlags))
			{
				return null;
			}

			$normalizedElement['optionFlags'] = $optionFlags;
		}

		if (array_key_exists('options', $element))
		{
			if (!is_array($element['options']) || !self::isMap($element['options']))
			{
				return null;
			}

			$normalizedElement['options'] = $element['options'];
		}

		return $normalizedElement;
	}

	private static function hasOnlyFields(array $value, array $allowedFields): bool
	{
		return array_diff(array_keys($value), $allowedFields) === [];
	}

	private static function isNonEmptyString(mixed $value): bool
	{
		return is_string($value) && trim($value) !== '';
	}

	private static function isMap(array $value): bool
	{
		foreach (array_keys($value) as $key)
		{
			if (!is_string($key))
			{
				return false;
			}
		}

		return true;
	}
}
