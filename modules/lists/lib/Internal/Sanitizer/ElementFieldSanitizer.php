<?php

declare(strict_types=1);

namespace Bitrix\Lists\Internal\Sanitizer;

use CBXSanitizer;

/**
 * Cleans user-submitted element fields before they reach CIBlockElement::Add/Update.
 *
 * NAME is plain text, so markup is stripped. PREVIEW_TEXT / DETAIL_TEXT keep the visual editor
 * markup but lose active content (script, event handlers, javascript: urls), mirroring the
 * sanitizer the iblock admin form applies to the same fields.
 */
final class ElementFieldSanitizer
{
	public static function sanitizeName(mixed $value): string
	{
		return trim(strip_tags(self::toString($value)));
	}

	public static function sanitizeHtml(mixed $value): string
	{
		$sanitizer = new CBXSanitizer();
		$sanitizer->setLevel(CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);

		return $sanitizer->sanitizeHtml(self::toString($value));
	}

	/**
	 * @param array $field Field description from CList::getFields().
	 * @return array{TYPE: string, VALUE: string}
	 */
	public static function prepareTextField(array $field, mixed $value): array
	{
		if (self::usesEditor($field))
		{
			return ['TYPE' => 'html', 'VALUE' => self::sanitizeHtml($value)];
		}

		return ['TYPE' => 'text', 'VALUE' => self::toString($value)];
	}

	private static function usesEditor(array $field): bool
	{
		return
			isset($field['SETTINGS'])
			&& is_array($field['SETTINGS'])
			&& ($field['SETTINGS']['USE_EDITOR'] ?? 'N') === 'Y';
	}

	private static function toString(mixed $value): string
	{
		if (is_array($value))
		{
			$value = current($value);
		}

		return is_scalar($value) ? (string)$value : '';
	}
}
