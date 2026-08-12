<?php
namespace Bitrix\Landing\Block;

final class StickyWrapperDetector
{
	private const REQUIRED_CLASSES = [
		'sticky',
		'top-0',
	];

	private const Z_INDEX_CLASSES = [
		'z-50' => 'landing-sticky-wrapper-z-50',
		'z-40' => 'landing-sticky-wrapper-z-40',
	];

	private const BASE_WRAPPER_CLASS = 'landing-sticky-wrapper';

	public static function getWrapperClass(string $html): string
	{
		$classList = self::extractRootSectionClassList($html);
		if ($classList === [])
		{
			return '';
		}

		$classMap = array_fill_keys($classList, true);
		foreach (self::REQUIRED_CLASSES as $requiredClass)
		{
			if (!isset($classMap[$requiredClass]))
			{
				return '';
			}
		}

		foreach (self::Z_INDEX_CLASSES as $zIndexClass => $wrapperClass)
		{
			if (isset($classMap[$zIndexClass]))
			{
				return self::BASE_WRAPPER_CLASS . ' ' . $wrapperClass;
			}
		}

		return '';
	}

	private static function extractRootSectionClassList(string $html): array
	{
		$html = trim($html);
		if ($html === '')
		{
			return [];
		}

		if (!preg_match('/^<section\b([^>]*)>/iu', $html, $matches))
		{
			return [];
		}

		$attributes = (string)($matches[1] ?? '');
		if (!preg_match('/\sclass\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', $attributes, $classMatches))
		{
			return [];
		}

		$classString = '';
		foreach ([2, 3, 4] as $classMatchIndex)
		{
			$classString = (string)($classMatches[$classMatchIndex] ?? '');
			if ($classString !== '')
			{
				break;
			}
		}
		if ($classString === '')
		{
			return [];
		}

		$classList = preg_split('/\s+/u', trim($classString));
		if (!is_array($classList))
		{
			return [];
		}

		return array_values(array_filter($classList, static fn(string $class): bool => $class !== ''));
	}
}
