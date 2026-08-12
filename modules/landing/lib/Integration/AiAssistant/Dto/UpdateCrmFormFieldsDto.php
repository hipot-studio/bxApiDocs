<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

readonly class UpdateCrmFormFieldsDto
{
	private function __construct(
		#[PositiveNumber]
		public ?int $formId = null,
		#[PositiveNumber]
		public ?int $pageId = null,
		#[PositiveNumber]
		public ?int $blockId = null,
		#[NotEmpty]
		public ?string $expectedFieldsHash = null,
		public array $operations = [],
		public bool $confirmSynchronization = false,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			formId: self::mapInteger($props, 'formId'),
			pageId: self::mapInteger($props, 'pageId'),
			blockId: self::mapInteger($props, 'blockId'),
			expectedFieldsHash: self::mapString($props, 'expectedFieldsHash'),
			operations: self::mapOperations($props, 'operations'),
			confirmSynchronization: self::mapBool($props, 'confirmSynchronization') ?? false,
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

	private static function mapBool(array $props, string $key): ?bool
	{
		if (!array_key_exists($key, $props) || $props[$key] === null)
		{
			return null;
		}

		if (is_bool($props[$key]))
		{
			return $props[$key];
		}

		if (is_int($props[$key]) || is_string($props[$key]))
		{
			return filter_var($props[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		}

		return null;
	}

	private static function mapOperations(array $props, string $key): array
	{
		if (!array_key_exists($key, $props) || !is_array($props[$key]))
		{
			return [];
		}

		return array_values(array_filter(
			$props[$key],
			static fn(mixed $operation): bool => is_array($operation),
		));
	}
}
