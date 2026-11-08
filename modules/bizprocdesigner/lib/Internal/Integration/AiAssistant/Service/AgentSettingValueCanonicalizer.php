<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

/**
 * Canonical form of a single agent setting value: a pure, deterministic function applied to *both* sides of every
 * baseline comparison (ALG-01). It exists because the two sides reach the comparison through different paths - the
 * baseline is the raw output of the forward converter, while the incoming value has already been normalized by
 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\AgentSettingValueValidator}. Without a
 * canonical form the very same unchanged value fingerprints differently on the two sides and a verbatim round-trip
 * is falsely refused (fail-closed, but unfixable by the agent).
 *
 * It reproduces exactly the two normalizations the validator performs, and nothing more:
 *   - a single non-multiple USER value persisted as a one-element list collapses to its scalar (ALG-03);
 *   - a MAP value transported as a JSON string decodes to its array.
 * The two are alternatives, never composed: a setting has one type, so a value is either coerced as an array or
 * decoded as a string. Keeping them exclusive means `['12']` and `'12'` become equal (the coercion) while `'["12"]'`
 * and `['12']` do not - the canonical form must not merge values the validator itself keeps apart.
 *
 * Associative arrays are key-sorted recursively while list order is preserved: an ordered field list is
 * semantically significant, so reordering it is a change.
 */
final class AgentSettingValueCanonicalizer
{
	public static function canonicalize(mixed $value, bool $normalizeTransport = true): mixed
	{
		if (is_array($value))
		{
			return self::canonicalizeStructure($normalizeTransport ? self::collapseSingleValueList($value) : $value);
		}

		if ($normalizeTransport && is_string($value))
		{
			$decoded = self::decodeMapString($value);

			return $decoded === null ? $value : self::canonicalizeStructure($decoded);
		}

		return $value;
	}

	/**
	 * Mirrors the USER coercion: an empty list stands for an empty value, a one-element list for its scalar.
	 * Every other shape is refused by the validator, so it is left untouched here.
	 */
	private static function collapseSingleValueList(array $value): string|array
	{
		if (!array_is_list($value))
		{
			return $value;
		}

		if ($value === [])
		{
			return '';
		}

		if (count($value) === 1 && is_scalar($value[0]))
		{
			return (string)$value[0];
		}

		return $value;
	}

	/**
	 * Mirrors the MAP decode. Only a string that decodes to an array is treated as a transported map - that is the
	 * one case the validator accepts; decoding scalars too ('12' -> 12, 'null' -> null) would merge values the
	 * validator keeps distinct.
	 */
	private static function decodeMapString(string $value): ?array
	{
		$trimmed = ltrim($value);
		if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '['))
		{
			// Only these can decode to an array, and the cheap check keeps every ordinary value (a code, an
			// expression, a whole PHP condition) off the exception path.
			return null;
		}

		try
		{
			$decoded = Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	private static function canonicalizeStructure(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$isList = array_is_list($value);
		$canonical = [];
		foreach ($value as $key => $item)
		{
			$canonical[$key] = self::canonicalizeStructure($item);
		}

		if (!$isList)
		{
			ksort($canonical);
		}

		return $canonical;
	}
}
