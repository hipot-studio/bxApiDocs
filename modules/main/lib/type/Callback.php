<?php

declare(strict_types=1);

namespace Bitrix\Main\Type;

use Bitrix\Main\ArgumentException;
use JsonSerializable;

final class Callback implements JsonSerializable
{
	private const TYPE = 'agent.callback.v1';
	private const JSON_OPTIONS =
		JSON_UNESCAPED_UNICODE
		| JSON_UNESCAPED_SLASHES
		| JSON_PRESERVE_ZERO_FRACTION
		| JSON_THROW_ON_ERROR
	;
	private const ENVELOPE_KEYS = ['type', 'callback', 'arguments'];

	private readonly string|array $callback;
	private readonly array $arguments;

	public function __construct(string|array $callback, array $arguments = [])
	{
		$this->callback = self::validateCallback($callback);
		self::assertList($arguments, 'arguments');
		self::validateValue($arguments);
		$this->arguments = $arguments;
	}

	public function __toString(): string
	{
		$callbackName = is_string($this->callback)
			? $this->callback
			: $this->callback[0] . '::' . $this->callback[1]
		;

		return $callbackName . '();';
	}

	public function getCallback(): string|array
	{
		return $this->callback;
	}

	public function getArguments(): array
	{
		return $this->arguments;
	}

	public function toJson(): string
	{
		return json_encode($this->jsonSerialize(), self::JSON_OPTIONS);
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => self::TYPE,
			'callback' => $this->callback,
			'arguments' => $this->arguments,
		];
	}

	public static function tryFromJson(string $value): ?self
	{
		if (!str_contains($value, self::TYPE))
		{
			return null;
		}

		try
		{
			$data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return null;
		}

		if (!is_array($data) || array_keys($data) !== self::ENVELOPE_KEYS)
		{
			return null;
		}

		if ($data['type'] !== self::TYPE)
		{
			return null;
		}

		if (!is_string($data['callback']) && !is_array($data['callback']))
		{
			return null;
		}

		if (!is_array($data['arguments']) || !array_is_list($data['arguments']))
		{
			return null;
		}

		try
		{
			return new self($data['callback'], $data['arguments']);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	private static function validateCallback(string|array $callback): string|array
	{
		if (is_string($callback))
		{
			self::assertNonEmptyUtf8String($callback, 'callback');

			return $callback;
		}

		if (
			array_keys($callback) !== [0, 1]
			|| !is_string($callback[0])
			|| !is_string($callback[1])
		)
		{
			throw new ArgumentException('Callback must be a function name or [class, method].', 'callback');
		}

		self::assertNonEmptyUtf8String($callback[0], 'callback.class');
		self::assertNonEmptyUtf8String($callback[1], 'callback.method');

		return $callback;
	}

	private static function validateValue(mixed $value): void
	{
		if ($value === null || is_bool($value) || is_int($value))
		{
			return;
		}

		if (is_string($value))
		{
			if (preg_match('//u', $value) !== 1)
			{
				throw new ArgumentException('Arguments must contain valid UTF-8 strings.', 'arguments');
			}

			return;
		}

		if (is_float($value))
		{
			if (!is_finite($value))
			{
				throw new ArgumentException('Arguments must contain finite floats.', 'arguments');
			}

			return;
		}

		if (is_array($value))
		{
			self::validateArray($value);

			return;
		}

		throw new ArgumentException('Arguments contain unsupported value.', 'arguments');
	}

	private static function validateArray(array $value): void
	{
		if (array_is_list($value))
		{
			foreach ($value as $item)
			{
				self::validateValue($item);
			}

			return;
		}

		foreach ($value as $key => $item)
		{
			if (!is_string($key))
			{
				throw new ArgumentException(
					'Nested arguments must be lists or string-key maps.',
					'arguments'
				);
			}

			if (preg_match('//u', $key) !== 1)
			{
				throw new ArgumentException('Argument map keys must be valid UTF-8.', 'arguments');
			}

			self::validateValue($item);
		}
	}

	private static function assertList(array $value, string $path): void
	{
		if (!array_is_list($value))
		{
			throw new ArgumentException($path . ' must be a list.', $path);
		}
	}

	private static function assertNonEmptyUtf8String(string $value, string $path): void
	{
		if (trim($value) === '')
		{
			throw new ArgumentException($path . ' must be a non-empty string.', $path);
		}

		self::assertUtf8String($value, $path);
	}

	private static function assertUtf8String(string $value, string $path): void
	{
		if (preg_match('//u', $value) !== 1)
		{
			throw new ArgumentException($path . ' must be valid UTF-8.', $path);
		}
	}
}
