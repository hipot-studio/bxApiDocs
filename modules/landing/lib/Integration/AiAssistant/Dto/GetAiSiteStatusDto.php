<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

readonly class GetAiSiteStatusDto
{
	private const DEFAULT_SECONDS = 0;
	public const MAX_SECONDS = 60;

	private function __construct(
		public string $jobId = '',
		public int $seconds = self::DEFAULT_SECONDS,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			jobId: AiSiteGenerationHelper::normalizeJobId($props['jobId'] ?? null),
			seconds: self::normalizeWaitSeconds($props['seconds'] ?? null),
		);
	}

	private static function normalizeWaitSeconds(mixed $value): int
	{
		if (!is_numeric($value))
		{
			return self::DEFAULT_SECONDS;
		}

		return min(self::MAX_SECONDS, max(self::DEFAULT_SECONDS, (int)$value));
	}
}
