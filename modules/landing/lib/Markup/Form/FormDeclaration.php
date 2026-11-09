<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Landing\Copilot\Generation\Step\Helper\CrmFormColorNormalizer;
use Bitrix\Landing\Markup\Validator;

/**
 * An element of the `forms` declaration of a markup package (DTO-F1, external contract).
 *
 * A broken structure (the token, a value of a wrong type) refuses the element as a whole.
 * Unsupported values do not: a field of an unknown kind goes to `ignoredKinds`, an invalid
 * design value is dropped. Service fields of the portal (`binding`, `formId`, `formName`)
 * never come from outside: only the keys of the contract survive the normalization.
 */
class FormDeclaration
{
	public const KINDS = ['name', 'last_name', 'phone', 'email', 'comment', 'company'];
	public const STYLES = ['modern', 'classic', 'fun'];
	public const THEMES = ['light', 'dark'];

	public const LIMIT_FORMS = 5;
	public const LIMIT_FIELDS = 15;

	private const COLOR_REGEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';
	private const FONT_FAMILY_REGEX = '/^[\p{L}\p{N} _\'",.-]{1,100}$/u';
	private const FONT_URI_REGEX = '#^https://[^\s"\'<>\\\\]+$#i';

	/**
	 * @return array{
	 *     token: string,
	 *     name?: string,
	 *     fields: list<array{kind: string, label?: string, required?: bool}>,
	 *     design?: array,
	 *     ignoredKinds: list<string>,
	 * }|null null when the structure of the element is broken
	 */
	public static function normalize(mixed $raw): ?array
	{
		if (!is_array($raw))
		{
			return null;
		}

		$token = $raw['token'] ?? null;
		if (!is_string($token) || !preg_match(Validator::TOKEN_REGEX, $token))
		{
			return null;
		}
		$form = ['token' => $token];

		if (isset($raw['name']))
		{
			if (!is_string($raw['name']))
			{
				return null;
			}
			$name = trim($raw['name']);
			if ($name !== '')
			{
				$form['name'] = $name;
			}
		}

		$fields = self::normalizeFields($raw['fields'] ?? []);
		if ($fields === null)
		{
			return null;
		}
		$form['fields'] = $fields['fields'];

		if (isset($raw['design']))
		{
			$design = self::normalizeDesign($raw['design']);
			if ($design === null)
			{
				return null;
			}
			if ($design)
			{
				$form['design'] = $design;
			}
		}

		$form['ignoredKinds'] = $fields['ignoredKinds'];

		return $form;
	}

	/**
	 * @return array{fields: list<array>, ignoredKinds: list<string>}|null
	 */
	private static function normalizeFields(mixed $rawFields): ?array
	{
		if (!is_array($rawFields) || !array_is_list($rawFields))
		{
			return null;
		}

		$fields = [];
		$ignoredKinds = [];
		foreach ($rawFields as $rawField)
		{
			if (!is_array($rawField) || !is_string($rawField['kind'] ?? null))
			{
				return null;
			}
			$field = ['kind' => $rawField['kind']];

			if (isset($rawField['label']))
			{
				if (!is_string($rawField['label']))
				{
					return null;
				}
				$label = trim($rawField['label']);
				if ($label !== '')
				{
					$field['label'] = $label;
				}
			}
			if (isset($rawField['required']))
			{
				if (!is_bool($rawField['required']))
				{
					return null;
				}
				$field['required'] = $rawField['required'];
			}

			if (!in_array($field['kind'], self::KINDS, true))
			{
				if (!in_array($field['kind'], $ignoredKinds, true))
				{
					$ignoredKinds[] = $field['kind'];
				}
				continue;
			}
			$fields[] = $field;
		}

		return [
			'fields' => $fields,
			'ignoredKinds' => $ignoredKinds,
		];
	}

	private static function normalizeDesign(mixed $rawDesign): ?array
	{
		if (!is_array($rawDesign))
		{
			return null;
		}

		$design = [];

		if (isset($rawDesign['colors']))
		{
			$colors = self::normalizeColors($rawDesign['colors']);
			if ($colors === null)
			{
				return null;
			}
			if ($colors)
			{
				$design['colors'] = $colors;
			}
		}

		if (isset($rawDesign['font']))
		{
			$font = self::normalizeFont($rawDesign['font']);
			if ($font === null)
			{
				return null;
			}
			if ($font)
			{
				$design['font'] = $font;
			}
		}

		foreach (['style' => self::STYLES, 'theme' => self::THEMES] as $key => $allowed)
		{
			if (!isset($rawDesign[$key]))
			{
				continue;
			}
			if (!is_string($rawDesign[$key]))
			{
				return null;
			}
			if (in_array($rawDesign[$key], $allowed, true))
			{
				$design[$key] = $rawDesign[$key];
			}
		}

		return $design;
	}

	private static function normalizeColors(mixed $rawColors): ?array
	{
		if (!is_array($rawColors))
		{
			return null;
		}

		$colors = [];
		foreach (CrmFormColorNormalizer::COLOR_KEYS as $key)
		{
			if (!isset($rawColors[$key]))
			{
				continue;
			}
			if (!is_string($rawColors[$key]))
			{
				return null;
			}
			if (preg_match(self::COLOR_REGEX, $rawColors[$key]))
			{
				$colors[$key] = $rawColors[$key];
			}
		}

		return $colors;
	}

	/**
	 * A font without a valid family is dropped as a whole: the uri alone styles nothing.
	 */
	private static function normalizeFont(mixed $rawFont): ?array
	{
		if (!is_array($rawFont))
		{
			return null;
		}

		$family = $rawFont['family'] ?? null;
		$uri = $rawFont['uri'] ?? null;
		if (($family !== null && !is_string($family)) || ($uri !== null && !is_string($uri)))
		{
			return null;
		}

		$family = trim((string)$family);
		if (!preg_match(self::FONT_FAMILY_REGEX, $family))
		{
			return [];
		}
		$font = ['family' => $family];

		if ($uri !== null && preg_match(self::FONT_URI_REGEX, $uri))
		{
			$font['uri'] = $uri;
		}

		return $font;
	}
}
