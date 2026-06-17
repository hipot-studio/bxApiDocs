<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest;

use Bitrix\Main\Error;
use Bitrix\Main\Type\ParameterDictionary;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Wrapper over request parameters with validation.
 * Throws RequestValidationException on missing or invalid params.
 */
class RequestParams
{
	public function __construct(private readonly ParameterDictionary $json)
	{
	}

	public function requireString(string $name): string
	{
		$value = $this->json->get($name);
		if ($value === null || $value === '')
		{
			throw new RequestValidationException([
				new Error("Parameter \"{$name}\" is required.", 'MISSING_' . strtoupper($name)),
			]);
		}

		return (string)$value;
	}

	public function requireInt(string $name): int
	{
		$value = $this->json->get($name);
		if ($value === null)
		{
			throw new RequestValidationException([
				new Error("Parameter \"{$name}\" is required.", 'MISSING_' . strtoupper($name)),
			]);
		}

		return (int)$value;
	}

	public function requireArray(string $name): array
	{
		$value = $this->json->get($name);
		if ($value === null || !is_array($value) || empty($value))
		{
			throw new RequestValidationException([
				new Error(
					"Parameter \"{$name}\" is required and must be a non-empty array.",
					'MISSING_' . strtoupper($name),
				),
			]);
		}

		return $value;
	}

	public function getString(string $name, ?string $default = null): ?string
	{
		$value = $this->json->get($name);

		return $value !== null ? (string)$value : $default;
	}

	public function getInt(string $name, ?int $default = null): ?int
	{
		$value = $this->json->get($name);

		return $value !== null ? (int)$value : $default;
	}

	public function getBool(string $name, bool $default = false): bool
	{
		return (bool)($this->json->get($name) ?? $default);
	}

	public function getNullableBool(string $name): ?bool
	{
		$value = $this->json->get($name);

		return $value === null ? null : (bool)$value;
	}

	public function getArray(string $name, array $default = []): array
	{
		return $this->json->get($name) ?? $default;
	}

	/**
	 * @template T of \BackedEnum
	 * @param string $name
	 * @param class-string<T> $enumClass
	 * @param bool $caseInsensitive If true, value is uppercased before tryFrom()
	 * @return T
	 */
	public function requireEnum(string $name, string $enumClass, bool $caseInsensitive = false): \BackedEnum
	{
		$value = $this->requireString($name);

		if ($caseInsensitive)
		{
			$value = strtoupper($value);
		}

		$enum = $enumClass::tryFrom($value);
		if ($enum === null)
		{
			$allowed = implode(', ', array_map(fn($c) => $c->value, $enumClass::cases()));
			throw new RequestValidationException([
				new Error(
					"Invalid \"{$name}\" value. Allowed: {$allowed}.",
					'INVALID_' . strtoupper($name),
				),
			]);
		}

		return $enum;
	}

	/**
	 * Ensures at least one of the given parameters is present in the request.
	 *
	 * @param string ...$names
	 */
	public function requireAnyOf(string ...$names): void
	{
		foreach ($names as $name)
		{
			if ($this->json->get($name) !== null)
			{
				return;
			}
		}

		$quoted = implode(', ', array_map(fn($n) => "\"{$n}\"", $names));
		throw new RequestValidationException([
			new Error(
				"At least one of {$quoted} must be provided.",
				'MISSING_FIELDS',
			),
		]);
	}
}
