<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

readonly class GetMarkupJobStatusDto
{
	private const DEFAULT_WAIT = 0;
	public const MAX_WAIT = 20;

	private function __construct(
		public string $jobId = '',
		public int $wait = self::DEFAULT_WAIT,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			jobId: AiSiteGenerationHelper::normalizeJobId($props['jobId'] ?? null),
			wait: self::normalizeWait($props['wait'] ?? null),
		);
	}

	private static function normalizeWait(mixed $value): int
	{
		if (!is_numeric($value))
		{
			return self::DEFAULT_WAIT;
		}

		return min(self::MAX_WAIT, max(self::DEFAULT_WAIT, (int)$value));
	}
}
