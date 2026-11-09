<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

readonly class GetAiSiteHandoffLinkDto
{
	private function __construct(
		public string $target = '',
		public string $request = '',
		public int $siteId = 0,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			target: self::normalizeString($props['target'] ?? null),
			request: self::normalizeString($props['request'] ?? null),
			siteId: self::normalizeId($props['siteId'] ?? null),
		);
	}

	private static function normalizeString(mixed $value): string
	{
		if (!is_string($value))
		{
			return '';
		}

		return trim($value);
	}

	private static function normalizeId(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}
}
