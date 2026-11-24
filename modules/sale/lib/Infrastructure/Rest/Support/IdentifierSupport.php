<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Support;

/**
 * The single reading of an entity identifier arriving from the transport.
 *
 * A cast is not a check. The transport carries the identifier as a string
 * (`Rest\V3\Interaction\Request\IdRequest`, `UpdateRequest`, `DeleteRequest`) and nothing on the way
 * makes sure the string is a number, so `(int)` turns `"123abc"` and `"123.9"` into 123: a mistyped
 * identifier silently names an existing entity instead of being refused. Checking after the cast
 * cannot notice that, so the check happens here, on the raw value, and every caller reads the
 * identifier through this class instead of casting on its own.
 */
final class IdentifierSupport
{
	/**
	 * @return int|null the identifier the value names exactly, or null when it names none
	 */
	public static function readPositive(mixed $value): ?int
	{
		if (!self::namesAnInteger($value))
		{
			return null;
		}

		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		return $id === false ? null : $id;
	}

	/**
	 * Whether the value names a whole number at all. A string is a form of its own here, not a
	 * leniency: the requests of the platform declare the identifier a string. A field of a data type
	 * is measured by its JSON type instead, before this is ever asked ({@see FieldShapeSupport}).
	 */
	private static function namesAnInteger(mixed $value): bool
	{
		// A boolean passes FILTER_VALIDATE_INT as 0/1, and a float is not how the transport spells
		// a whole number: both are values that only a cast would turn into one.
		if (!is_int($value) && !is_string($value))
		{
			return false;
		}

		return filter_var($value, FILTER_VALIDATE_INT) !== false;
	}
}
