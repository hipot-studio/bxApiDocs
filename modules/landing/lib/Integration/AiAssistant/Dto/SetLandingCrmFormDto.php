<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;

readonly class SetLandingCrmFormDto
{
	private function __construct(
		#[PositiveNumber]
		public ?int $pageId = null,
		#[PositiveNumber]
		public ?int $blockId = null,
		#[PositiveNumber]
		public ?int $formId = null,
		public ?string $formToken = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			pageId: self::mapInteger($props, 'pageId'),
			blockId: self::mapInteger($props, 'blockId'),
			formId: self::mapInteger($props, 'formId'),
			formToken: self::mapString($props, 'formToken'),
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
		if (!array_key_exists($key, $props) || !is_string($props[$key]))
		{
			return null;
		}

		$value = trim($props[$key]);

		return $value !== '' ? $value : null;
	}
}
