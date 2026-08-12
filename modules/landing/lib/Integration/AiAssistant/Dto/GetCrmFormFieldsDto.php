<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\PositiveNumber;

readonly class GetCrmFormFieldsDto
{
	private function __construct(
		#[PositiveNumber]
		public ?int $formId = null,
		#[PositiveNumber]
		public ?int $pageId = null,
		#[PositiveNumber]
		public ?int $blockId = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			formId: self::mapInteger($props, 'formId'),
			pageId: self::mapInteger($props, 'pageId'),
			blockId: self::mapInteger($props, 'blockId'),
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
}
