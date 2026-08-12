<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;

class DeleteLandingSiteDto
{
	private function __construct(
		#[PositiveNumber]
		public readonly ?int $siteId = null,
		public readonly bool $confirm = false,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			siteId: self::mapInteger($props, 'siteId'),
			confirm: self::mapBool($props, 'confirm') ?? false,
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

	private static function mapBool(array $props, string $key): ?bool
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (!is_bool($props[$key]) && !is_scalar($props[$key]))
		{
			return null;
		}

		return filter_var($props[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
	}
}
