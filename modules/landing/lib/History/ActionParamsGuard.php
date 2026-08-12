<?php

namespace Bitrix\Landing\History;

use Bitrix\Landing\History\Action\BaseAction;
use Bitrix\Landing\Sanitizer;

/**
 * Validates HTML-bearing History action params before persistence and apply.
 */
final class ActionParamsGuard
{
	/**
	 * Universal fail-closed RCE marker: reports whether any string leaf of an
	 * ACTION_PARAMS tree carries a raw PHP open tag (<?), recursing through nested
	 * arrays including MULTIPLY children. Non-mutating and does not run the XSS
	 * auditor, so it is safe and linear over settings/CSS/URL values.
	 */
	public static function containsUnneutralizedPhpOpenTagDeep(mixed $value): bool
	{
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (self::containsUnneutralizedPhpOpenTagDeep($item))
				{
					return true;
				}
			}

			return false;
		}

		if (is_string($value))
		{
			return Sanitizer::containsUnneutralizedPhpOpenTag($value);
		}

		return false;
	}

	/**
	 * @param list<string> $keys
	 * @param class-string<BaseAction> $actionClass
	 */
	public static function validateParams(array $params, array $keys, string $actionClass = BaseAction::class): bool
	{
		if ($keys === [])
		{
			return true;
		}

		foreach ($keys as $key)
		{
			if (!array_key_exists($key, $params))
			{
				continue;
			}

			if (!self::isAllowedValue($actionClass, $key, $params[$key]))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return mixed|null null when node payload must be rejected
	 */
	public static function prepareNodeValue(mixed $value): mixed
	{
		if (is_string($value))
		{
			return self::prepareHtmlForSave($value);
		}

		if (is_array($value))
		{
			return self::prepareNodeArray($value);
		}

		return $value;
	}

	/**
	 * Sanitize HTML for block save; null when content must be rejected.
	 */
	public static function prepareHtmlForSave(string $content): ?string
	{
		$bad = false;
		$sanitized = (new Sanitizer())->sanitizeText($content, $bad);

		return $bad ? null : $sanitized;
	}

	/**
	 * @param list<string> $keys
	 * @param class-string<BaseAction> $actionClass
	 * @param array<string, mixed> $params
	 */
	public static function rejectUnsafeValueParams(
		array $params,
		array $keys,
		string $actionClass = BaseAction::class,
	): array
	{
		if ($keys === [])
		{
			return $params;
		}

		if (self::validateParams($params, $keys, $actionClass))
		{
			return $params;
		}

		foreach ($keys as $key)
		{
			if (array_key_exists($key, $params))
			{
				$params[$key] = is_array($params[$key]) ? [] : '';
			}
		}

		return $params;
	}

	private static function prepareNodeArray(array $value): ?array
	{
		$result = [];

		foreach ($value as $key => $item)
		{
			if (is_string($item))
			{
				$prepared = self::prepareHtmlForSave($item);
				if ($prepared === null)
				{
					return null;
				}
				$result[$key] = $prepared;

				continue;
			}

			if (is_array($item))
			{
				$prepared = self::prepareNodeArray($item);
				if ($prepared === null)
				{
					return null;
				}
				$result[$key] = $prepared;

				continue;
			}

			$result[$key] = $item;
		}

		return $result;
	}

	/**
	 * @param class-string<BaseAction> $actionClass
	 */
	private static function isAllowedValue(string $actionClass, string $key, mixed $value): bool
	{
		if (is_string($value))
		{
			$value = $actionClass::normalizeSanitizableParam($key, $value);

			return self::isAllowedHtmlString((string)$value);
		}

		if (is_array($value))
		{
			return self::isAllowedArray($value);
		}

		return true;
	}

	private static function isAllowedHtmlString(string $content): bool
	{
		if ($content === '')
		{
			return true;
		}

		$bad = false;
		(new Sanitizer())->sanitizeText($content, $bad);

		return !$bad;
	}

	private static function isAllowedArray(array $value): bool
	{
		foreach ($value as $item)
		{
			if (is_string($item) && !self::isAllowedHtmlString($item))
			{
				return false;
			}

			if (is_array($item) && !self::isAllowedArray($item))
			{
				return false;
			}
		}

		return true;
	}
}
