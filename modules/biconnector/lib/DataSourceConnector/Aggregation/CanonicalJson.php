<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * JSON Canonicalization Scheme, RFC 8785 (design §2.5).
 *
 * The value domain of the protocol holds objects, arrays, strings, integers, booleans and null;
 * floating point numbers never appear in a canonicalized block, so their serialization rules are
 * not implemented and a float is rejected instead of being silently rounded.
 *
 * The output carries no trailing separator: the fingerprint is computed over exactly these bytes,
 * and a trailing newline would change it.
 */
final class CanonicalJson
{
	private const ESCAPES = [
		"\x08" => '\\b',
		"\x09" => '\\t',
		"\x0A" => '\\n',
		"\x0C" => '\\f',
		"\x0D" => '\\r',
		'"' => '\\"',
		'\\' => '\\\\',
	];

	private function __construct()
	{
	}

	public static function encode(mixed $value): string
	{
		if ($value === null)
		{
			return 'null';
		}

		if (is_bool($value))
		{
			return $value ? 'true' : 'false';
		}

		if (is_int($value))
		{
			return (string)$value;
		}

		if (is_string($value))
		{
			return self::encodeString($value);
		}

		if ($value instanceof \stdClass)
		{
			return self::encodeObject((array)$value);
		}

		if (is_array($value))
		{
			return self::isList($value) ? self::encodeArray($value) : self::encodeObject($value);
		}

		throw new \InvalidArgumentException(
			'Canonical JSON accepts objects, arrays, strings, integers, booleans and null only.'
		);
	}

	/**
	 * SHA-256 of the canonical form, lowercase hex.
	 */
	public static function fingerprint(mixed $value): string
	{
		return hash('sha256', self::encode($value));
	}

	private static function encodeArray(array $value): string
	{
		$items = [];
		foreach ($value as $item)
		{
			$items[] = self::encode($item);
		}

		return '[' . implode(',', $items) . ']';
	}

	private static function encodeObject(array $value): string
	{
		$keys = array_map('strval', array_keys($value));
		usort($keys, [self::class, 'compareKeys']);

		$members = [];
		foreach ($keys as $key)
		{
			$members[] = self::encodeString($key) . ':' . self::encode($value[$key]);
		}

		return '{' . implode(',', $members) . '}';
	}

	/**
	 * RFC 8785 §3.2.3 orders property names by UTF-16 code units, so the comparison is done on the
	 * UTF-16BE representation and not on the UTF-8 bytes.
	 */
	private static function compareKeys(string $left, string $right): int
	{
		return strcmp(self::toUtf16($left), self::toUtf16($right));
	}

	private static function toUtf16(string $value): string
	{
		$converted = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

		return $converted === false ? $value : $converted;
	}

	private static function encodeString(string $value): string
	{
		$result = '"';
		$length = strlen($value);
		for ($i = 0; $i < $length; $i++)
		{
			$char = $value[$i];
			if (isset(self::ESCAPES[$char]))
			{
				$result .= self::ESCAPES[$char];
			}
			elseif ($char < "\x20")
			{
				$result .= sprintf('\\u%04x', ord($char));
			}
			else
			{
				$result .= $char;
			}
		}

		return $result . '"';
	}

	private static function isList(array $value): bool
	{
		if ($value === [])
		{
			return true;
		}

		return array_keys($value) === range(0, count($value) - 1);
	}
}
