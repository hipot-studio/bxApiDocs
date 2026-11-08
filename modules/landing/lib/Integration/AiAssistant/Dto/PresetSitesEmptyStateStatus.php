<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

final class PresetSitesEmptyStateStatus
{
	private function __construct(
		private readonly bool $actual,
		private readonly bool $aiAvailable,
		private readonly int $remainingSeconds,
	)
	{
	}

	public static function actual(int $remainingSeconds): self
	{
		return new self(true, false, max(0, $remainingSeconds));
	}

	public static function notActual(bool $aiAvailable = false): self
	{
		return new self(false, $aiAvailable, 0);
	}

	public function isActual(): bool
	{
		return $this->actual;
	}

	public function isAiAvailable(): bool
	{
		return $this->aiAvailable;
	}

	public function getRemainingSeconds(): int
	{
		return $this->remainingSeconds;
	}
}
