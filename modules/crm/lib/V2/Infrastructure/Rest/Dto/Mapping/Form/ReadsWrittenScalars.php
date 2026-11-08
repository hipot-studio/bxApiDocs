<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Main\Error;

/**
 * The one reading of a written scalar, shared by the write mappings of the family: the form itself
 * and the embedding of one form.
 *
 * A value has one accepted shape wherever it arrives and a refusal one wording: two copies of the
 * rule would let the same input be taken by one document and refused by the other. Null passes
 * through untouched - what a cleared path means is the business of the document that carries it.
 */
trait ReadsWrittenScalars
{
	/**
	 * @param Error[] $errors
	 */
	private static function asString(mixed $value, string $path, array &$errors): ?string
	{
		if ($value === null || is_string($value))
		{
			return $value;
		}

		if (is_int($value) || is_float($value))
		{
			return (string)$value;
		}

		$errors[] = self::refusal($path, 'a string');

		return null;
	}

	/**
	 * @param Error[] $errors
	 */
	private static function asInt(mixed $value, string $path, array &$errors): ?int
	{
		if ($value === null || is_int($value))
		{
			return $value;
		}

		if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
		{
			return (int)$value;
		}

		$errors[] = self::refusal($path, 'a number');

		return null;
	}

	/**
	 * A flag is written by `true` and `false`, and by `0` and `1` - the one non-boolean shape of a
	 * JSON body whose intention is not in doubt. A string is refused: taken for a flag, `"N"` would
	 * switch on what it was sent to switch off.
	 *
	 * @param Error[] $errors
	 */
	private static function asBool(mixed $value, string $path, array &$errors): ?bool
	{
		if ($value === null || is_bool($value))
		{
			return $value;
		}

		if ($value === 0 || $value === 1)
		{
			return (bool)$value;
		}

		$errors[] = self::refusal($path, 'true or false');

		return null;
	}

	private static function refusal(string $path, string $expected): Error
	{
		return new Error(sprintf('`%s` expects %s', $path, $expected), $path);
	}
}
