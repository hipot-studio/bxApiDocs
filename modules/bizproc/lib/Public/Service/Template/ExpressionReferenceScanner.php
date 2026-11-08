<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Template;

use Bitrix\Bizproc\Workflow\Template\SourceType;

/**
 * A reference to a data element is text and is found as text: nothing is instantiated, so a property of an
 * activity whose class the portal no longer has - an application deleted, a module turned off - answers about
 * its references exactly as a property of a living one. An inline reference stands both on its own and inside
 * a calculated expression, so one parse covers `{=Source:Key}` and `{{=...}}`. Both the values and the keys of
 * an array are read: a property addressing its target by the key of a row holds the element it names. Text
 * carrying neither the opening of an expression nor the key itself is left to the cheap check and never
 * reaches the parse.
 */
final class ExpressionReferenceScanner
{
	private const EXPRESSION_OPENING = '{=';

	/**
	 * @param string[] $sourceTypes
	 */
	public static function hasReference(mixed $value, array $sourceTypes, string $key): bool
	{
		if (is_array($value))
		{
			foreach ($value as $itemKey => $item)
			{
				// The key of a row carries a reference as its value does: `SetGlobalVariableActivity` keeps the
				// value it writes under the expression of its target.
				if (
					self::hasReference((string)$itemKey, $sourceTypes, $key)
					|| self::hasReference($item, $sourceTypes, $key)
				)
				{
					return true;
				}
			}

			return false;
		}

		if (!is_string($value) || !self::looksLikeReference($value, $key))
		{
			return false;
		}

		foreach (\CBPActivity::findExpressions($value) as $expression)
		{
			$source = SourceType::getObjectSourceType((string)$expression['object'], (string)$expression['field']);

			if (
				$source !== null
				&& in_array($source[0], $sourceTypes, true)
				&& mb_strtolower((string)($source[1] ?? '')) === mb_strtolower($key)
			)
			{
				return true;
			}
		}

		return false;
	}

	private static function looksLikeReference(string $value, string $key): bool
	{
		return str_contains($value, self::EXPRESSION_OPENING) && stripos($value, $key) !== false;
	}
}
