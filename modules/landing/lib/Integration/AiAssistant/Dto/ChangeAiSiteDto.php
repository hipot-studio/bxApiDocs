<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

readonly class ChangeAiSiteDto
{
	private function __construct(
		public int $pageId = 0,
		public array $briefLines = [],
		public array $selectedElement = [],
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			pageId: self::normalizePageId($props['pageId'] ?? null),
			briefLines: self::normalizeBriefLines($props['briefLines'] ?? null),
			selectedElement: self::normalizeSelectedElement($props['selectedElement'] ?? null),
		);
	}

	private static function normalizePageId(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}

	private static function normalizeBriefLines(mixed $value): array
	{
		if (is_string($value))
		{
			return [$value];
		}

		if (!is_array($value))
		{
			return [];
		}

		return array_values(
			array_filter(
				$value,
				static fn(mixed $item): bool => is_string($item),
			),
		);
	}

	private static function normalizeSelectedElement(mixed $value): array
	{
		if (!is_array($value) || array_is_list($value))
		{
			return [];
		}

		$blockId = self::normalizePageId($value['blockId'] ?? null);
		$selector = self::normalizeSelector($value['selector'] ?? null);

		if ($blockId <= 0 || $selector === '')
		{
			return [];
		}

		return [
			'blockId' => $blockId,
			'selector' => $selector,
		];
	}

	private static function normalizeSelector(mixed $value): string
	{
		if (!is_string($value))
		{
			return '';
		}

		return trim($value);
	}
}
