<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\AttachedObject;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * Canonical form of the owner parameters of an attached object: the form they are stored in and hashed from.
 * Keys are sorted by strcmp, the JSON is ASCII only (characters out of the BMP as surrogate pairs) and slashes
 * are kept unescaped. Errors name the offending key, never its value.
 */
final class OwnerParametersCodec
{
	public const MAX_KEYS = 8;
	public const MAX_STRING_LENGTH = 64;
	public const MAX_BYTES = 512;
	public const KEY_PATTERN = '/^[a-z][A-Za-z0-9_]{0,31}$/D';

	/** Equal to AttachedObject::ERROR_OWNER_PARAMETERS_INVALID. */
	public const ERROR_INVALID = 'DISK_AO_OWNER_PARAMETERS_INVALID';

	/**
	 * @param array<string, int|string|bool> $parameters
	 * @return Result On success getData()['value'] is the canonical JSON.
	 */
	public function encode(array $parameters): Result
	{
		$result = new Result();

		if ($parameters === [] || array_is_list($parameters))
		{
			return $result->addError($this->error('Owner parameters must be a non-empty map with string keys.'));
		}

		if (count($parameters) > self::MAX_KEYS)
		{
			return $result->addError(
				$this->error('Owner parameters must contain at most ' . self::MAX_KEYS . ' keys.'),
			);
		}

		foreach ($parameters as $key => $value)
		{
			if (!is_string($key) || !preg_match(self::KEY_PATTERN, $key))
			{
				return $result->addError(
					$this->error('Owner parameter key ' . $this->describeKey($key) . ' is invalid.'),
				);
			}

			if (!$this->isValidValue($value))
			{
				return $result->addError($this->error(
					'Owner parameter "' . $key . '" must be an int, a bool or a valid UTF-8 string of at most '
					. self::MAX_STRING_LENGTH . ' characters.',
				));
			}
		}

		ksort($parameters, SORT_STRING);

		try
		{
			$json = Json::encode($parameters, JSON_UNESCAPED_SLASHES);
		}
		catch (ArgumentException)
		{
			return $result->addError($this->error('Owner parameters cannot be encoded.'));
		}

		if (strlen($json) > self::MAX_BYTES)
		{
			return $result->addError(
				$this->error('Encoded owner parameters must not exceed ' . self::MAX_BYTES . ' bytes.'),
			);
		}

		return $result->setData(['value' => $json]);
	}

	/**
	 * Stored value back to the map; a value that is not a JSON object reads as no parameters.
	 *
	 * @return array<string, int|string|bool>|null
	 */
	public function decode(?string $stored): ?array
	{
		if ($stored === null)
		{
			return null;
		}

		try
		{
			$parameters = Json::decode($stored);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($parameters) ? $parameters : null;
	}

	public function hash(string $canonical): string
	{
		return hash('sha256', $canonical);
	}

	private function isValidValue(mixed $value): bool
	{
		if (is_int($value) || is_bool($value))
		{
			return true;
		}

		return
			is_string($value)
			&& mb_check_encoding($value, 'UTF-8')
			&& mb_strlen($value, 'UTF-8') <= self::MAX_STRING_LENGTH
		;
	}

	private function describeKey(int|string $key): string
	{
		$key = (string)$key;

		return preg_match('/^[\x21-\x7E]{1,32}$/D', $key) ? '"' . $key . '"' : '(not printable)';
	}

	private function error(string $message): Error
	{
		return new Error($message, self::ERROR_INVALID);
	}
}
