<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;

readonly class CreateAiSiteDto
{
	private function __construct(
		public array $briefLines = [],
		public int $bindingId = 0,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			briefLines: self::normalizeBriefLines($props['briefLines'] ?? null),
			bindingId: self::normalizePositiveInteger($props[AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD] ?? null),
		);
	}

	private static function normalizeBriefLines(mixed $value): array
	{
		if (is_string($value))
		{
			return CreateAiSiteState::normalizeInputLines([$value]);
		}

		if (!is_array($value))
		{
			return [];
		}

		return CreateAiSiteState::normalizeInputLines($value);
	}

	private static function normalizePositiveInteger(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}
}
