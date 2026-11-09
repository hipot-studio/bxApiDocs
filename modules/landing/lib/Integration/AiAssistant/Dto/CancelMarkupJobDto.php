<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\AiSiteGenerationHelper;

readonly class CancelMarkupJobDto
{
	private function __construct(
		public string $jobId = '',
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			jobId: AiSiteGenerationHelper::normalizeJobId($props['jobId'] ?? null),
		);
	}
}
