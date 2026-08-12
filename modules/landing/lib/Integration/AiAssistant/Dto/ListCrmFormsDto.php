<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

readonly class ListCrmFormsDto
{
	private function __construct(
		public ?string $query = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			query: self::mapString($props, 'query'),
		);
	}

	private static function mapString(array $props, string $key): ?string
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (!is_scalar($props[$key]))
		{
			return null;
		}

		$value = trim((string)$props[$key]);

		return $value !== '' ? $value : null;
	}
}
