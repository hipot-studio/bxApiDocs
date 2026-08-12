<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;

readonly class ListCrmFormAvailableFieldsDto
{
	private function __construct(
		#[PositiveNumber]
		public ?int $formId = null,
		public ?string $query = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			formId: self::mapInteger($props, 'formId'),
			query: self::mapString($props, 'query'),
		);
	}

	private static function mapInteger(array $props, string $key): ?int
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (is_bool($props[$key]) || !is_scalar($props[$key]))
		{
			return null;
		}

		$value = filter_var($props[$key], FILTER_VALIDATE_INT);

		return $value !== false ? (int)$value : null;
	}

	private static function mapString(array $props, string $key): ?string
	{
		if (!array_key_exists($key, $props) || $props[$key] === null || !is_scalar($props[$key]))
		{
			return null;
		}

		$value = trim((string)$props[$key]);

		return $value !== '' ? $value : null;
	}
}
